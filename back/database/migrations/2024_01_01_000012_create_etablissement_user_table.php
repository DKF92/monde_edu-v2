<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table pivot permettant a un meme utilisateur (ex: fondateur d'un groupe scolaire)
     * d'acceder a plusieurs etablissements. Les roles/permissions par etablissement
     * sont geres separement via le systeme "teams" de spatie/laravel-permission
     * (voir create_permission_tables), en utilisant etablissement_id comme team_id.
     */
    public function up(): void
    {
        Schema::create('etablissement_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_defaut')->default(false);
            $table->timestamps();

            $table->unique(['etablissement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etablissement_user');
    }
};
