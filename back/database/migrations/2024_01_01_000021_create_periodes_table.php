<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remplace la table "trimestre" de la v1: gere aussi bien un decoupage
     * en trimestres qu'en semestres selon le parametrage de l'etablissement.
     */
    public function up(): void
    {
        Schema::create('periodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->enum('type_decoupage', ['trimestre', 'semestre']);
            $table->unsignedTinyInteger('numero');
            $table->string('libelle', 30);
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_cloturee')->default(false);
            $table->timestamps();

            $table->unique(['annee_scolaire_id', 'type_decoupage', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodes');
    }
};
