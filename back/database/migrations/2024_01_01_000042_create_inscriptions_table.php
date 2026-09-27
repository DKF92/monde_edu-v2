<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inscription d'un eleve dans une classe pour une annee scolaire donnee.
     *
     * Le detail par type de frais (v1: montant_fr_inscr, cout_craie, ...) est
     * porte par frais_eleves (une ligne par type de frais) plutot que par des
     * dizaines de colonnes figees, ce qui permet a chaque etablissement de
     * definir ses propres frais annexes sans migration de schema.
     *
     * En revanche, comme en v1, on NE VEUT PAS avoir a faire une jointure/somme
     * sur frais_eleves a chaque fois qu'on affiche une liste d'eleves avec leur
     * situation financiere : montant_total_du/reduit/paye sont donc des totaux
     * mis en cache ici, recalcules automatiquement (voir App\Models\Inscription
     * ::recalculerTotaux(), appele par les modeles Reduction et ReglementLigne)
     * a chaque paiement ou reduction. Une lecture directe de "inscriptions"
     * donne donc la meme simplicite que la table v1, sans la duplication de
     * colonnes par type de frais.
     */
    public function up(): void
    {
        Schema::create('inscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('eleve_id')->constrained('eleves')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->dateTime('date_inscription');
            $table->enum('statut', ['en_cours', 'validee', 'annulee'])->default('en_cours');
            $table->boolean('redoublant')->default(false);
            $table->boolean('affecte')->default(false)->comment('affecte par l\'Etat / orientation officielle');
            $table->boolean('boursier')->default(false);
            $table->string('etablissement_origine', 200)->nullable();
            $table->string('classe_origine', 30)->nullable();
            $table->decimal('montant_total_du', 12, 0)->default(0);
            $table->decimal('montant_total_reduit', 12, 0)->default(0);
            $table->decimal('montant_total_paye', 12, 0)->default(0);
            $table->foreignId('enregistre_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['annee_scolaire_id', 'eleve_id'], 'inscriptions_annee_eleve_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions');
    }
};
