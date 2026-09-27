<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 300);
            $table->decimal('montant', 12, 0);
            $table->string('categorie', 100)->nullable();
            $table->string('beneficiaire', 150)->nullable();
            $table->foreignId('payeur_id')->nullable()->constrained('personnels')->nullOnDelete();
            $table->string('piece_justificative_path')->nullable();
            $table->date('date_depense');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
