<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des mises a jour depuis l'inscription en ligne (site de l'Etat) :
 * une ligne par inscription mise a jour, avec ses elements modifies (avant /
 * apres) appliques directement ou en attente.
 *
 * En attente (jamais applique directement) :
 * - statut > 1 (paiement enregistre) : statut affecte et niveau ;
 * - statut 1 (classe choisie) : niveau.
 * niveau_demande_id / affecte_demande gardent la valeur en ligne a reporter :
 * l'element est traite quand l'inscription a cette valeur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mises_a_jour_en_ligne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('annee_scolaire_id')->constrained('annees_scolaires')->cascadeOnDelete();
            $table->foreignId('inscription_id')->nullable()->constrained('inscriptions')->nullOnDelete();
            $table->foreignId('eleve_id')->nullable()->constrained('eleves')->nullOnDelete();
            // Statut de l'inscription au moment de la mise a jour (0 a 3).
            $table->unsignedTinyInteger('statut_inscription');
            // [{champ, libelle, avant, apres, applique}]
            $table->json('changements');
            $table->json('remarques')->nullable();
            $table->boolean('en_attente')->default(false)->index();
            $table->foreignId('niveau_demande_id')->nullable()->constrained('niveaux')->nullOnDelete();
            $table->boolean('affecte_demande')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mises_a_jour_en_ligne');
    }
};
