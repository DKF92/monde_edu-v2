<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mise a jour en ligne : inscriptions deja scannees sans recu trouve sur le
 * site de l'Etat (« introuvables en ligne »). Elles restent a verifier (le
 * bouton « Mettre a jour » les rescanne) mais se distinguent des inscriptions
 * jamais scannees, et figurent dans Inscriptions > Mises a jour en ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inscriptions', function (Blueprint $table) {
            // Dernier scan sans recu trouve ; null : jamais scannee ou trouvee.
            $table->timestamp('en_ligne_introuvable_le')->nullable()->after('inscription_en_ligne');
        });
        Schema::table('mises_a_jour_en_ligne', function (Blueprint $table) {
            // mise_a_jour : recu trouve et repris ; introuvable : aucun recu (une ligne par inscription).
            $table->string('type', 20)->default('mise_a_jour')->after('eleve_id')->index();
            $table->unsignedSmallInteger('tentatives')->default(1)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('mises_a_jour_en_ligne', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'tentatives']);
        });
        Schema::table('inscriptions', function (Blueprint $table) {
            $table->dropColumn('en_ligne_introuvable_le');
        });
    }
};
