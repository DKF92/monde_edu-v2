<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periodes')->cascadeOnDelete();
            $table->foreignId('type_examen_id')->constrained('types_examens')->cascadeOnDelete();
            $table->foreignId('personnel_id')->constrained('personnels')->cascadeOnDelete();
            $table->decimal('valeur', 5, 2);
            $table->unsignedTinyInteger('coefficient')->default(1);
            $table->date('date_evaluation')->nullable();
            $table->timestamps();
        });

        // Moyenne d'un eleve dans une matiere pour une periode (calculee a partir
        // de "notes", mais persistee pour l'historique et la performance des
        // bulletins/classements une fois la periode arretee).
        Schema::create('moyennes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periodes')->cascadeOnDelete();
            $table->decimal('moyenne', 5, 2);
            $table->unsignedTinyInteger('coefficient');
            $table->unsignedSmallInteger('rang')->nullable();
            $table->string('appreciation', 50)->nullable();
            $table->boolean('is_arretee')->default(false);
            $table->timestamps();

            $table->unique(['eleve_id', 'matiere_id', 'periode_id'], 'moyenne_eleve_matiere_periode_unique');
        });

        // Moyenne generale + classement d'un eleve pour une periode (remplace les
        // dizaines de colonnes rang_class_* / moy_class_* figees sur "classe").
        Schema::create('moyennes_generales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('periode_id')->constrained('periodes')->cascadeOnDelete();
            $table->decimal('moyenne_generale', 5, 2);
            $table->unsignedSmallInteger('rang_classe')->nullable();
            $table->unsignedSmallInteger('rang_niveau')->nullable();
            $table->string('mention', 50)->nullable();
            $table->string('decision', 50)->nullable();
            $table->timestamps();

            $table->unique(['eleve_id', 'periode_id'], 'moyenne_generale_eleve_periode_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moyennes_generales');
        Schema::dropIfExists('moyennes');
        Schema::dropIfExists('notes');
    }
};
