<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace la table "enseigner" de la v1: quel professeur enseigne quelle
     * matiere dans quelle classe, pour l'annee scolaire en cours.
     */
    public function up(): void
    {
        Schema::create('affectations_enseignants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnels')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->unsignedTinyInteger('volume_horaire_hebdo')->nullable();
            $table->timestamps();

            $table->unique(['classe_id', 'matiere_id', 'annee_scolaire_id'], 'affect_classe_matiere_annee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affectations_enseignants');
    }
};
