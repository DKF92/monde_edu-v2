<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parent/tuteur d'un ou plusieurs eleves, potentiellement dans plusieurs
     * etablissements (fratrie repartie). Compte "users" optionnel pour l'acces
     * au portail parent de la PWA.
     */
    public function up(): void
    {
        Schema::create('tuteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('nom', 80);
            $table->string('prenoms', 180);
            $table->string('telephone1', 20)->nullable();
            $table->string('telephone2', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('profession', 100)->nullable();
            $table->string('adresse', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuteurs');
    }
};
