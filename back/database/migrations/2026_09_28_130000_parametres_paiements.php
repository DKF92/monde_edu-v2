<?php

use App\Models\Etablissement;
use App\Services\EtablissementProvisioningService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametres des paiements, iso V1 :
 * - V1 cout_formation a 2 lignes par niveau et par annee : eleve affecte par
 *   l'Etat / non affecte (montants et minimum differents) -> colonne
 *   "affecte" sur grilles_tarifaires ;
 * - V1 distinguait "reduction" (is_cas=1) et "cas" (is_cas=2) avec des
 *   bornes codees en dur -> table types_reductions, parametrable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->boolean('affecte')->default(false)->after('niveau_id')->comment('V1 cout_formation.affecte');
            $table->unique(['annee_scolaire_id', 'niveau_id', 'affecte'], 'grille_tarifaire_annee_niveau_affecte_unique');
        });
        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->dropUnique('grille_tarifaire_annee_niveau_unique');
        });

        Schema::create('types_reductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->enum('base', ['frais_principaux', 'total'])->default('frais_principaux')
                ->comment('frais_principaux = inscription/scolarite seulement ; total = y compris les annexes');
            $table->decimal('montant_minimum', 12, 0)->default(0);
            $table->boolean('minimum_frais_principaux')->default(false)
                ->comment('la reduction doit au moins couvrir le reste des frais principaux (V1 "cas")');
            $table->unsignedTinyInteger('plafond_pourcentage')->nullable()->comment('% maximum de la base, null = toute la base');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['etablissement_id', 'code']);
        });

        Schema::table('reductions', function (Blueprint $table) {
            $table->foreignId('type_reduction_id')->nullable()->after('etablissement_id')
                ->constrained('types_reductions')->nullOnDelete();
        });

        $provisioning = app(EtablissementProvisioningService::class);
        Etablissement::withTrashed()->each(function (Etablissement $etablissement) use ($provisioning) {
            $provisioning->provisionTypesFrais($etablissement);
            $provisioning->provisionTypesReductions($etablissement);
        });
    }

    public function down(): void
    {
        Schema::table('reductions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('type_reduction_id');
        });
        Schema::dropIfExists('types_reductions');

        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->unique(['annee_scolaire_id', 'niveau_id'], 'grille_tarifaire_annee_niveau_unique');
        });
        Schema::table('grilles_tarifaires', function (Blueprint $table) {
            $table->dropUnique('grille_tarifaire_annee_niveau_affecte_unique');
            $table->dropColumn('affecte');
        });
    }
};
