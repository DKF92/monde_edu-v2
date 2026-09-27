<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 15);
            $table->string('libelle', 100);
            $table->unsignedTinyInteger('coefficient_defaut')->default(1);
            $table->string('groupe_bulletin', 50)->nullable()->comment('ex: LITTERAIRE, SCIENTIFIQUE - pour le regroupement sur le bulletin');
            $table->timestamps();

            $table->unique(['etablissement_id', 'code']);
        });

        // Coefficient specifique d'une matiere selon le niveau (remplace la colonne
        // figee "coef_mat" + "id_niveau" unique de la v1: une matiere peut desormais
        // etre enseignee sur plusieurs niveaux avec des coefficients differents).
        Schema::create('matiere_niveau', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_id')->constrained()->cascadeOnDelete();
            $table->foreignId('niveau_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('coefficient');
            $table->boolean('is_obligatoire')->default(true);
            $table->timestamps();

            $table->unique(['matiere_id', 'niveau_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matiere_niveau');
        Schema::dropIfExists('matieres');
    }
};
