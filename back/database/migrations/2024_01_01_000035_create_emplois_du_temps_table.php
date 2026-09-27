<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace la table "emploi_temps" de la v1 qui stockait chaque jour dans
     * 11 colonnes mat1..mat11 / s1..s11 (creneaux figes). Ici une ligne = un
     * creneau, ce qui permet un nombre de creneaux/jour illimite et des requetes
     * simples (ex: emploi du temps d'un professeur, detection de conflits de salle).
     */
    public function up(): void
    {
        Schema::create('emplois_du_temps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnels')->cascadeOnDelete();
            $table->enum('jour', ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi']);
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->string('salle', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emplois_du_temps');
    }
};
