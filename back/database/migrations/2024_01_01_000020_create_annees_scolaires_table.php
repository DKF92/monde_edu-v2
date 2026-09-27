<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annees_scolaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 10)->comment('ex: 2025-2026');
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_cloturee')->default(false);
            $table->timestamps();

            $table->unique(['etablissement_id', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annees_scolaires');
    }
};
