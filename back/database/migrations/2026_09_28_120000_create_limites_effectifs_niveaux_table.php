<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effectif maximum par classe, sur 3 niveaux de precision (le plus precis
 * l'emporte) :
 * 1. classe         : classes.capacite            (V1 classe.limite_effclas) ;
 * 2. niveau (6EME)  : cette table                 (V1 niveau.eff_limite_classe) ;
 * 3. etablissement  : etablissements.parametres["effectif_max_classe"].
 *
 * Les niveaux etant un referentiel global, la limite d'un niveau est
 * rattachee a l'etablissement ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limites_effectifs_niveaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etablissement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('niveau_id')->constrained('niveaux')->cascadeOnDelete();
            $table->unsignedSmallInteger('effectif_max');
            $table->timestamps();

            $table->unique(['etablissement_id', 'niveau_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limites_effectifs_niveaux');
    }
};
