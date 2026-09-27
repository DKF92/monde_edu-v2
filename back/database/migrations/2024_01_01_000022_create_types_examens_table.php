<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_examens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 100);
            $table->unsignedTinyInteger('ponderation')->default(1)->comment('poids de ce type d\'evaluation dans la moyenne');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('types_examens');
    }
};
