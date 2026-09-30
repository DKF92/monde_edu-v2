<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inscriptions iso V1 (table "inscription") :
 * - classe facultative : l'eleve peut etre inscrit avant le choix de sa classe
 *   (V1 inscr_termine = 0) ;
 * - statut = etape V1 inscr_termine : sans_classe (0), en_attente (1, classe
 *   attribuee), inscrit (2, premier paiement), solde (3) + annulee ;
 * - niveau suivi (V1 niveau_act), LV2 (V1 langue), decision de fin d'annee et
 *   niveau a suivre (V1 decision_finale / niveau_a_suivre), moyenne annuelle ;
 * - resultat de l'annee precedente pour un eleve venant d'ailleurs ;
 * - inscription en ligne sur le site de l'Etat (V1 inscr_ligne) + recu ;
 * - eleves.telephone (V1 num_eleve) ;
 * - frais en nature (V1 cout_craie / cout_rame, apportes par l'eleve) : une
 *   quantite a remettre au lieu d'un montant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inscriptions', function (Blueprint $table) {
            $table->foreignId('classe_id')->nullable()->change();
            $table->foreignId('niveau_id')->nullable()->after('classe_id')->constrained('niveaux')->nullOnDelete();
            $table->string('langue_vivante_2', 30)->nullable()->after('boursier');
            $table->string('decision_origine', 20)->nullable()->after('classe_origine')->comment('decision de fin d\'annee precedente (eleve venant d\'ailleurs)');
            $table->decimal('moyenne_origine', 5, 2)->nullable()->after('decision_origine');
            $table->string('decision_finale', 20)->nullable()->after('moyenne_origine')->comment('V1 decision_finale : ADMIS, REDOUBLE, EXCLU...');
            $table->decimal('moyenne_annuelle', 5, 2)->nullable()->after('decision_finale');
            $table->foreignId('niveau_a_suivre_id')->nullable()->after('moyenne_annuelle')->constrained('niveaux')->nullOnDelete();
            $table->boolean('inscrit_en_ligne')->default(false)->after('niveau_a_suivre_id')->comment('V1 inscr_ligne');
            $table->json('inscription_en_ligne')->nullable()->after('inscrit_en_ligne')->comment('recu du site de l\'Etat (numero, somme, date...)');
        });

        DB::statement("ALTER TABLE inscriptions MODIFY statut ENUM('en_cours','validee','sans_classe','en_attente','inscrit','solde','annulee') NOT NULL DEFAULT 'sans_classe'");
        DB::table('inscriptions')->where('statut', 'en_cours')->update(['statut' => 'en_attente']);
        DB::table('inscriptions')->where('statut', 'validee')->update(['statut' => 'inscrit']);
        DB::table('inscriptions')->whereNull('classe_id')->where('statut', '!=', 'annulee')->update(['statut' => 'sans_classe']);
        DB::statement("ALTER TABLE inscriptions MODIFY statut ENUM('sans_classe','en_attente','inscrit','solde','annulee') NOT NULL DEFAULT 'sans_classe'");
        DB::statement('UPDATE inscriptions i JOIN classes c ON c.id = i.classe_id SET i.niveau_id = c.niveau_id WHERE i.niveau_id IS NULL');

        Schema::table('eleves', function (Blueprint $table) {
            $table->string('telephone', 20)->nullable()->after('sexe')->comment('V1 num_eleve');
        });

        DB::statement("ALTER TABLE types_frais MODIFY nature ENUM('inscription','scolarite','annexe','en_nature','dette') NOT NULL DEFAULT 'annexe'");
        DB::table('types_frais')->whereIn('code', ['CRAIE', 'RAME'])->update(['nature' => 'en_nature']);
        // Une quantite (ex : 2 rames) plutot qu'un montant : on remet a 1 les
        // eventuels montants deja saisis pour ces frais.
        DB::table('grille_tarifaire_lignes')
            ->whereIn('type_frais_id', DB::table('types_frais')->where('nature', 'en_nature')->pluck('id'))
            ->where('montant', '>', 1)
            ->update(['montant' => 1]);

        Schema::table('frais_eleves', function (Blueprint $table) {
            $table->unsignedSmallInteger('quantite_due')->nullable()->after('montant_paye')->comment('frais en nature : nombre a apporter');
            $table->unsignedSmallInteger('quantite_remise')->default(0)->after('quantite_due');
        });

        // V1 : c'est l'educateur qui inscrit les eleves.
        $permissionId = DB::table('permissions')->where('name', 'inscriptions.gerer')->value('id');
        if ($permissionId) {
            foreach (DB::table('roles')->where('code', 'educateur')->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('frais_eleves', function (Blueprint $table) {
            $table->dropColumn(['quantite_due', 'quantite_remise']);
        });
        DB::table('types_frais')->where('nature', 'en_nature')->update(['nature' => 'annexe']);
        DB::statement("ALTER TABLE types_frais MODIFY nature ENUM('inscription','scolarite','annexe','dette') NOT NULL DEFAULT 'annexe'");

        Schema::table('eleves', function (Blueprint $table) {
            $table->dropColumn('telephone');
        });

        DB::statement("ALTER TABLE inscriptions MODIFY statut ENUM('en_cours','validee','sans_classe','en_attente','inscrit','solde','annulee') NOT NULL DEFAULT 'en_cours'");
        DB::table('inscriptions')->whereIn('statut', ['sans_classe', 'en_attente'])->update(['statut' => 'en_cours']);
        DB::table('inscriptions')->whereIn('statut', ['inscrit', 'solde'])->update(['statut' => 'validee']);
        DB::statement("ALTER TABLE inscriptions MODIFY statut ENUM('en_cours','validee','annulee') NOT NULL DEFAULT 'en_cours'");

        Schema::table('inscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('niveau_id');
            $table->dropConstrainedForeignId('niveau_a_suivre_id');
            $table->dropColumn([
                'langue_vivante_2', 'decision_origine', 'moyenne_origine', 'decision_finale',
                'moyenne_annuelle', 'inscrit_en_ligne', 'inscription_en_ligne',
            ]);
        });
    }
};
