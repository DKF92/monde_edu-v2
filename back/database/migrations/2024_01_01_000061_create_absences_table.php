<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Absences des eleves ET du personnel, distinguees par les colonnes
     * nullable eleve_id / personnel_id (une seule des deux renseignee).
     */
    public function up(): void
    {
        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('eleve_id')->nullable()->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnels')->cascadeOnDelete();
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->decimal('nombre_heures', 5, 1)->nullable();
            $table->boolean('is_justifiee')->default(false);
            $table->string('motif', 200)->nullable();
            $table->foreignId('saisi_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
