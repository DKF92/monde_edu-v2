<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Synchronisation serveur de l'ecole (fait foi) <-> serveur en ligne (copie) :
 * - sync_journal : chaque changement d'une ligne (rempli par des declencheurs,
 *   migration suivante) ; sync_etat : avancement (serveur de l'ecole) ;
 *   sync_rejets : saisies faites en ligne refusees a l'ecole (notes arretees...) ;
 * - rapports_textes et rapports_personnalises : sortis du JSON
 *   etablissements.parametres (table administrative recopiee de l'ecole vers
 *   le serveur en ligne, qui ecraserait les saisies faites en ligne) ;
 * - uuid (valeur par defaut MySQL, meme pour les ecritures directes en base)
 *   sur les tables pedagogiques ecrites des deux cotes.
 */
return new class extends Migration
{
    private const TABLES_UUID = ['notes', 'absences', 'moyennes', 'moyennes_generales'];

    public function up(): void
    {
        Schema::create('sync_journal', function (Blueprint $table) {
            $table->id();
            $table->string('table_nom', 64);
            $table->unsignedBigInteger('ligne_id')->nullable()->comment('cle primaire « id » de la ligne');
            $table->string('ligne_cle', 191)->nullable()->comment('cle composee (tables pivots sans id)');
            $table->enum('operation', ['ecriture', 'suppression']);
            $table->enum('origine', ['local', 'en_ligne']);
            $table->dateTime('cree_le', 6)->useCurrent();
            $table->dateTime('envoye_le')->nullable()->comment('transmis a l\'autre serveur');
            $table->index(['envoye_le', 'id']);
            $table->index(['table_nom', 'ligne_id']);
        });

        Schema::create('sync_etat', function (Blueprint $table) {
            $table->string('cle', 64)->primary();
            $table->text('valeur')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('sync_rejets', function (Blueprint $table) {
            $table->id();
            $table->string('table_nom', 64);
            $table->char('ligne_uuid', 36)->nullable();
            $table->json('donnees');
            $table->string('motif', 255);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cree_le')->useCurrent();
            $table->timestamp('vu_le')->nullable();
        });

        Schema::create('rapports_textes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->enum('type', ['rentree', 'periode', 'annuel']);
            $table->foreignId('periode_id')->nullable()->constrained('periodes')->cascadeOnDelete();
            $table->string('cle', 60)->comment('« {annee}-{type} » ou « {annee}-periode-{id} » (ancienne cle du JSON)');
            $table->json('textes');
            $table->foreignId('modifie_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['etablissement_id', 'cle']);
        });

        Schema::create('rapports_personnalises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('titre', 150);
            $table->json('configuration');
            $table->foreignId('modifie_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // MySQL refuse DEFAULT (uuid()) avec le journal binaire actif : un
        // declencheur « avant insertion » remplit l'uuid, meme pour les
        // ecritures directes en base (query builder).
        foreach ([...self::TABLES_UUID, 'rapports_textes', 'rapports_personnalises'] as $nom) {
            DB::statement("ALTER TABLE `$nom` ADD `uuid` CHAR(36) NULL AFTER `id`");
            DB::statement("UPDATE `$nom` SET `uuid` = UUID() WHERE `uuid` IS NULL");
            DB::unprepared("CREATE TRIGGER `{$nom}_uuid` BEFORE INSERT ON `$nom` FOR EACH ROW SET NEW.`uuid` = COALESCE(NEW.`uuid`, UUID())");
            DB::statement("ALTER TABLE `$nom` MODIFY `uuid` CHAR(36) NOT NULL");
            DB::statement("ALTER TABLE `$nom` ADD UNIQUE `{$nom}_uuid_unique` (`uuid`)");
        }

        // Reprise des donnees du JSON etablissements.parametres.
        foreach (DB::table('etablissements')->get(['id', 'parametres']) as $e) {
            $parametres = json_decode($e->parametres ?? 'null', true) ?: [];
            foreach ((array) ($parametres['rapports_textes'] ?? []) as $cle => $textes) {
                if (! preg_match('/^(\d+)-(rentree|annuel|periode-(\d+))$/', (string) $cle, $m) || ! DB::table('annees_scolaires')->where('id', $m[1])->exists()) {
                    continue;
                }
                $periodeId = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
                DB::table('rapports_textes')->insert([
                    'etablissement_id' => $e->id, 'annee_scolaire_id' => (int) $m[1], 'type' => $periodeId ? 'periode' : $m[2],
                    'periode_id' => $periodeId && DB::table('periodes')->where('id', $periodeId)->exists() ? $periodeId : null,
                    'cle' => (string) $cle, 'textes' => json_encode($textes, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ((array) ($parametres['rapports_personnalises'] ?? []) as $id => $config) {
                DB::table('rapports_personnalises')->insert([
                    'uuid' => (string) ($config['id'] ?? $id), 'etablissement_id' => $e->id, 'titre' => mb_substr((string) ($config['titre'] ?? 'Rapport'), 0, 150),
                    'configuration' => json_encode($config, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (array_key_exists('rapports_textes', $parametres) || array_key_exists('rapports_personnalises', $parametres)) {
                DB::table('etablissements')->where('id', $e->id)
                    ->update(['parametres' => DB::raw("JSON_REMOVE(parametres, '$.rapports_textes', '$.rapports_personnalises')")]);
            }
        }
    }

    public function down(): void
    {
        // Remet les textes et modeles dans le JSON des parametres.
        foreach (DB::table('etablissements')->pluck('id') as $id) {
            $textes = DB::table('rapports_textes')->where('etablissement_id', $id)->pluck('textes', 'cle')->map(fn ($t) => json_decode($t, true));
            $modeles = DB::table('rapports_personnalises')->where('etablissement_id', $id)->get()
                ->mapWithKeys(fn ($r) => [$r->uuid => json_decode($r->configuration, true)]);
            foreach (['rapports_textes' => $textes, 'rapports_personnalises' => $modeles] as $cle => $valeur) {
                if ($valeur->isNotEmpty()) {
                    DB::table('etablissements')->where('id', $id)->update(['parametres' => DB::raw(
                        "JSON_SET(COALESCE(parametres, JSON_OBJECT()), '$.$cle', CAST(".DB::getPdo()->quote(json_encode($valeur, JSON_UNESCAPED_UNICODE)).' AS JSON))'
                    )]);
                }
            }
        }
        foreach ([...self::TABLES_UUID, 'rapports_textes', 'rapports_personnalises'] as $nom) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$nom}_uuid`");
        }
        foreach (self::TABLES_UUID as $nom) {
            Schema::table($nom, fn (Blueprint $t) => $t->dropUnique("{$nom}_uuid_unique"));
            Schema::table($nom, fn (Blueprint $t) => $t->dropColumn('uuid'));
        }
        Schema::dropIfExists('rapports_personnalises');
        Schema::dropIfExists('rapports_textes');
        Schema::dropIfExists('sync_rejets');
        Schema::dropIfExists('sync_etat');
        Schema::dropIfExists('sync_journal');
    }
};
