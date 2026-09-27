<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referentiel global (PAS de etablissement_id) : les niveaux (6EME, CM2,
     * TLE, ...) sont les memes pour tous les etablissements, on ne les
     * duplique pas par ecole. Seules les donnees qui varient reellement par
     * etablissement (grille tarifaire, limites d'effectif, classes ouvertes
     * cette annee...) sont rattachees a un etablissement, via leur propre
     * table qui reference niveau_id.
     */
    public function up(): void
    {
        Schema::create('niveaux', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('libelle', 30)->comment('ex: 6EME, 2NDE C, CM2');
            $table->enum('cycle', ['maternelle', 'primaire', 'college', 'lycee'])->default('college');
            $table->unsignedTinyInteger('ordre')->default(0)->comment('ordre de progression pour le passage de classe');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('niveaux');
    }
};
