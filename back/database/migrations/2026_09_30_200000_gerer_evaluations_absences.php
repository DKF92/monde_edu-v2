<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluations, moyennes et absences (V1 note / moyenne / absence) :
 *
 * - notes : une evaluation = (classe, matiere, periode, numero), notee sur
 *   10, 20 ou 40 (V1 num_note, "notee sur") ; saisie par un utilisateur
 *   (le fondateur n'est pas forcement un personnel) ;
 * - moyennes : moyenne d'une matiere, arretee ou non (V1 arrete), avec la classe ;
 * - moyennes_generales : totaux et bilans lettres / sciences du bulletin ;
 * - absences : rattachees a l'etablissement, l'annee, la periode et la classe ;
 * - matiere "Conduite" (V1 CONDUITE, coefficient 1) creee pour chaque
 *   etablissement, et coefficients du college repris de la V1 (6EME a 3EME :
 *   matieres enseignees coefficient 1, LV2 et EDHC a partir de la 4EME).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->unsignedTinyInteger('numero')->default(1)->after('periode_id')->comment('numero de l\'evaluation dans la periode (V1 num_note)');
            $table->unsignedTinyInteger('bareme')->default(20)->after('numero')->comment('note sur 10, 20 ou 40');
            $table->foreignId('saisi_par_id')->nullable()->after('personnel_id')->constrained('users')->nullOnDelete();
        });
        DB::statement('ALTER TABLE notes MODIFY type_examen_id BIGINT UNSIGNED NULL, MODIFY personnel_id BIGINT UNSIGNED NULL');
        Schema::table('notes', function (Blueprint $table) {
            $table->unique(['eleve_id', 'matiere_id', 'periode_id', 'numero'], 'notes_eleve_matiere_periode_numero_unique');
            $table->index(['classe_id', 'matiere_id', 'periode_id']);
        });

        Schema::table('moyennes', function (Blueprint $table) {
            $table->foreignId('classe_id')->nullable()->after('eleve_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('saisi_par_id')->nullable()->after('is_arretee')->constrained('users')->nullOnDelete();
        });

        Schema::table('moyennes_generales', function (Blueprint $table) {
            $table->decimal('total_points', 7, 2)->nullable()->after('moyenne_generale');
            $table->unsignedSmallInteger('total_coefficients')->nullable()->after('total_points');
            $table->decimal('moyenne_lettres', 5, 2)->nullable()->after('total_coefficients');
            $table->decimal('moyenne_sciences', 5, 2)->nullable()->after('moyenne_lettres');
        });

        Schema::table('absences', function (Blueprint $table) {
            $table->foreignId('etablissement_id')->nullable()->after('id')->constrained('etablissements')->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->nullable()->after('etablissement_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('periode_id')->nullable()->after('annee_scolaire_id')->constrained('periodes')->nullOnDelete();
            $table->foreignId('classe_id')->nullable()->after('eleve_id')->constrained('classes')->nullOnDelete();
            $table->index(['etablissement_id', 'annee_scolaire_id', 'date_debut']);
        });

        $maintenant = now();
        foreach (DB::table('etablissements')->pluck('id') as $etablissement) {
            // Conduite (V1 : matiere CONDUITE, saisie par l'educateur, coefficient 1).
            if (! DB::table('matieres')->where('etablissement_id', $etablissement)->where('code', 'COND')->exists()) {
                DB::table('matieres')->insert([
                    'etablissement_id' => $etablissement, 'code' => 'COND', 'libelle' => 'Conduite',
                    'coefficient_defaut' => 1, 'groupe_bulletin' => 'AUTRES', 'created_at' => $maintenant, 'updated_at' => $maintenant,
                ]);
            }

            // Coefficients du college (V1 table matiere, niveaux 6eme a 3eme), seulement si rien n'est encore saisi.
            $matieres = DB::table('matieres')->where('etablissement_id', $etablissement)->pluck('id', 'code');
            if (DB::table('matiere_niveau')->whereIn('matiere_id', $matieres->values())->exists()) {
                continue;
            }
            $communes = ['FR', 'ANG', 'HG', 'MATH', 'PC', 'SVT', 'EPS', 'COND'];
            $parNiveau = [
                '6EME' => $communes,
                '5EME' => $communes,
                '4EME' => [...$communes, 'EDHC', 'ALL', 'ESP'],
                '3EME' => [...$communes, 'EDHC', 'ALL', 'ESP'],
            ];
            foreach ($parNiveau as $code => $codes) {
                $niveau = DB::table('niveaux')->where('code', $code)->value('id');
                foreach ($codes as $codeMatiere) {
                    if ($niveau && isset($matieres[$codeMatiere])) {
                        DB::table('matiere_niveau')->insert([
                            'matiere_id' => $matieres[$codeMatiere], 'niveau_id' => $niveau, 'coefficient' => 1,
                            // LV2 : seuls les eleves qui suivent la langue sont notes.
                            'is_obligatoire' => ! in_array($codeMatiere, ['ALL', 'ESP'], true),
                            'created_at' => $maintenant, 'updated_at' => $maintenant,
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('absences', function (Blueprint $table) {
            $table->dropIndex(['etablissement_id', 'annee_scolaire_id', 'date_debut']);
            $table->dropConstrainedForeignId('classe_id');
            $table->dropConstrainedForeignId('periode_id');
            $table->dropConstrainedForeignId('annee_scolaire_id');
            $table->dropConstrainedForeignId('etablissement_id');
        });
        Schema::table('moyennes_generales', function (Blueprint $table) {
            $table->dropColumn(['total_points', 'total_coefficients', 'moyenne_lettres', 'moyenne_sciences']);
        });
        Schema::table('moyennes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saisi_par_id');
            $table->dropConstrainedForeignId('classe_id');
        });
        Schema::table('notes', function (Blueprint $table) {
            $table->dropUnique('notes_eleve_matiere_periode_numero_unique');
            $table->dropIndex(['classe_id', 'matiere_id', 'periode_id']);
            $table->dropConstrainedForeignId('saisi_par_id');
            $table->dropColumn(['numero', 'bareme']);
        });
    }
};
