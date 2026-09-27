<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace "cout_formation" de la v1, qui stockait chaque frais annexe
     * (craie, rame, tee-shirt, carte d'acces, ...) dans une colonne dediee.
     * Ici: une grille par niveau/annee, et le detail des frais est normalise
     * dans grille_tarifaire_lignes (une ligne par type_frais), ce qui permet
     * a chaque etablissement de definir librement ses propres frais annexes.
     */
    public function up(): void
    {
        Schema::create('grilles_tarifaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('niveau_id')->constrained('niveaux')->cascadeOnDelete();
            $table->decimal('montant_minimum_inscription', 12, 0)->default(0);
            $table->timestamps();

            $table->unique(['annee_scolaire_id', 'niveau_id'], 'grille_tarifaire_annee_niveau_unique');
        });

        Schema::create('grille_tarifaire_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grille_tarifaire_id')->constrained('grilles_tarifaires')->cascadeOnDelete();
            $table->foreignId('type_frais_id')->constrained('types_frais')->cascadeOnDelete();
            $table->decimal('montant', 12, 0)->default(0);
            $table->timestamps();

            $table->unique(['grille_tarifaire_id', 'type_frais_id'], 'grille_ligne_unique');
        });

        // Echeancier de versement de la scolarite (remplace les 6 couples
        // date_versement_x/montant_x figes de "echeance_versement").
        Schema::create('echeanciers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('niveau_id')->nullable()->constrained('niveaux')->cascadeOnDelete();
            $table->string('libelle', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('echeancier_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('echeancier_id')->constrained('echeanciers')->cascadeOnDelete();
            $table->unsignedTinyInteger('numero_versement');
            $table->date('date_limite');
            $table->decimal('montant', 12, 0);
            $table->timestamps();

            $table->unique(['echeancier_id', 'numero_versement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echeancier_lignes');
        Schema::dropIfExists('echeanciers');
        Schema::dropIfExists('grille_tarifaire_lignes');
        Schema::dropIfExists('grilles_tarifaires');
    }
};
