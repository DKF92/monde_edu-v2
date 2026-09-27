<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une reduction cible soit une ligne de frais, soit une dette (exclusivite
     * validee au niveau applicatif, ex: FormRequest / model boot()).
     */
    public function up(): void
    {
        Schema::create('reductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('frais_eleve_id')->nullable()->constrained('frais_eleves')->cascadeOnDelete();
            $table->foreignId('dette_id')->nullable()->constrained('dettes')->cascadeOnDelete();
            $table->decimal('montant', 12, 0);
            $table->string('motif', 200)->nullable();
            $table->foreignId('accorde_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reductions');
    }
};
