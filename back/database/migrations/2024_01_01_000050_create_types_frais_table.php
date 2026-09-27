<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_frais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle', 100);
            $table->enum('nature', ['inscription', 'scolarite', 'annexe', 'dette'])->default('annexe');
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->boolean('is_obligatoire')->default(true);
            $table->timestamps();

            $table->unique(['etablissement_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types_frais');
    }
};
