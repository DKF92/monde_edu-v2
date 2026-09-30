<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // V1 "choix" (niveau_gen, mat_admin, annee) : niveaux dont s'occupe un
        // educateur, pour une annee scolaire (il ne voit/inscrit que ceux-la).
        Schema::create('educateur_niveaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnels')->cascadeOnDelete();
            $table->foreignId('niveau_id')->constrained('niveaux')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['annee_scolaire_id', 'personnel_id', 'niveau_id'], 'educateur_niveaux_unique');
        });

        // V1 administration.matiere_prof (une seule matiere) : ici plusieurs
        // matieres possibles par professeur, dans l'etablissement.
        Schema::create('personnel_matieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->constrained('personnels')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['personnel_id', 'matiere_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_matieres');
        Schema::dropIfExists('educateur_niveaux');
    }
};
