<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('matricule', 20);
            $table->string('nom', 80);
            $table->string('prenoms', 180);
            $table->date('date_naissance')->nullable();
            $table->string('lieu_naissance', 100)->nullable();
            $table->char('sexe', 1)->nullable();
            $table->string('nationalite', 60)->nullable();
            $table->string('photo_path')->nullable();
            $table->text('particularites_medicales')->nullable();
            $table->boolean('orphelin_pere')->default(false);
            $table->boolean('orphelin_mere')->default(false);
            $table->string('quartier', 150)->nullable();
            $table->enum('statut', ['actif', 'inactif', 'exclu', 'transfere'])->default('actif');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['etablissement_id', 'matricule']);
        });

        // Pivot eleve <-> tuteur(s), avec identification du contact principal / payeur.
        Schema::create('eleve_tuteur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('tuteur_id')->constrained('tuteurs')->cascadeOnDelete();
            $table->enum('lien_parente', ['pere', 'mere', 'tuteur_legal', 'autre']);
            $table->boolean('is_contact_principal')->default(false);
            $table->boolean('is_payeur')->default(false);
            $table->timestamps();

            $table->unique(['eleve_id', 'tuteur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eleve_tuteur');
        Schema::dropIfExists('eleves');
    }
};
