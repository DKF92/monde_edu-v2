<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil "personnel" en extension 1-1 de users, pour tout membre du staff
     * (professeur, educateur, caissier, comptable, econome, direction, ...).
     * Le role/poste effectif est porte par le systeme de roles (spatie), scope
     * par etablissement via team_id.
     */
    public function up(): void
    {
        Schema::create('personnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('diplome', 100)->nullable();
            $table->enum('statut_contrat', ['permanent', 'vacataire', 'stagiaire'])->nullable();
            $table->date('date_embauche')->nullable();
            $table->foreignId('matiere_principale_id')->nullable()->constrained('matieres')->nullOnDelete();
            $table->string('nationalite', 60)->nullable();
            $table->string('quartier', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnels');
    }
};
