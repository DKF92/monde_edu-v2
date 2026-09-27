<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le detail de ce qu'un eleve doit payer pour son inscription : une ligne
     * par type de frais, generee et FIGEE (montant_du) au moment de
     * l'inscription a partir de la grille tarifaire du niveau/annee - on ne
     * revient jamais consulter grilles_tarifaires ensuite, exactement comme
     * en v1 ou ces montants etaient recopies dans "inscription".
     *
     * montant_reduit et montant_paye sont des totaux mis en cache (recalcules
     * automatiquement par App\Models\Reduction et App\Models\ReglementLigne a
     * chaque ecriture), pour permettre une lecture directe - sans jointure ni
     * agregation - de "combien reste a payer pour ce type de frais".
     */
    public function up(): void
    {
        Schema::create('frais_eleves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscription_id')->constrained('inscriptions')->cascadeOnDelete();
            $table->foreignId('type_frais_id')->constrained('types_frais')->cascadeOnDelete();
            $table->decimal('montant_du', 12, 0);
            $table->decimal('montant_reduit', 12, 0)->default(0);
            $table->decimal('montant_paye', 12, 0)->default(0);
            $table->timestamps();

            $table->unique(['inscription_id', 'type_frais_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frais_eleves');
    }
};
