<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un versement effectue par un eleve/tuteur. Contrairement a la v1 ou
     * chaque reglement etait rattache a un seul "code_regle", un versement
     * peut ici couvrir plusieurs lignes de frais et/ou une dette a la fois
     * (voir reglement_lignes), ce qui correspond a l'usage reel a la caisse.
     */
    public function up(): void
    {
        Schema::create('reglements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('caissier_id')->constrained('personnels')->cascadeOnDelete();
            $table->string('numero_recu', 30);
            $table->decimal('montant_total', 12, 0);
            $table->enum('mode_paiement', ['especes', 'mobile_money', 'cheque', 'virement'])->default('especes');
            $table->dateTime('date_paiement');
            $table->timestamps();

            $table->unique(['etablissement_id', 'numero_recu']);
        });

        Schema::create('reglement_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reglement_id')->constrained('reglements')->cascadeOnDelete();
            $table->foreignId('frais_eleve_id')->nullable()->constrained('frais_eleves')->cascadeOnDelete();
            $table->foreignId('dette_id')->nullable()->constrained('dettes')->cascadeOnDelete();
            $table->decimal('montant', 12, 0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglement_lignes');
        Schema::dropIfExists('reglements');
    }
};
