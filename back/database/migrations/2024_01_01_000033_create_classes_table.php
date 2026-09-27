<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('niveau_id')->constrained('niveaux')->cascadeOnDelete();
            $table->string('libelle', 20)->comment('ex: 6EME A');
            $table->string('salle', 30)->nullable();
            $table->unsignedSmallInteger('capacite')->nullable();
            $table->string('langue_vivante_2', 30)->nullable();
            $table->foreignId('professeur_principal_id')->nullable()->constrained('personnels')->nullOnDelete();
            $table->foreignId('educateur_id')->nullable()->constrained('personnels')->nullOnDelete();
            $table->timestamps();

            $table->unique(['annee_scolaire_id', 'niveau_id', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
