<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Types de frais activables, et applicables ou non a chaque type d'eleve
 * (affecte / non affecte). Ex : la scolarite ne s'applique pas a un eleve
 * affecte (regle V1, auparavant codee en dur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('types_frais', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_obligatoire');
            $table->boolean('applicable_affecte')->default(true)->after('is_active');
            $table->boolean('applicable_non_affecte')->default(true)->after('applicable_affecte');
        });

        DB::table('types_frais')->where('nature', 'scolarite')->update(['applicable_affecte' => false]);
    }

    public function down(): void
    {
        Schema::table('types_frais', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'applicable_affecte', 'applicable_non_affecte']);
        });
    }
};
