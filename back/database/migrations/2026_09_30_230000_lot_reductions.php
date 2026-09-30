<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une reduction accordee (menu Reductions) peut se repartir sur plusieurs
 * frais (cas social : frais principaux puis annexes) : ses lignes partagent
 * le meme "lot", qui l'identifie dans la liste et a la suppression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reductions', function (Blueprint $table) {
            $table->uuid('lot')->nullable()->after('etablissement_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('reductions', function (Blueprint $table) {
            $table->dropIndex(['lot']);
            $table->dropColumn('lot');
        });
    }
};
