<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caisse (V1 reglement) :
 * - inscription_id : inscription reglee (V1 reglement.id_inscr), null pour un
 *   versement qui ne regle qu'une dette ;
 * - numero_versement : 1er, 2e... versement de l'inscription (V1 num_reglement,
 *   6 au maximum, le dernier doit tout solder) ;
 * - date_expiration : date de validite du recu (V1 reglement.date_expiration,
 *   controlee au billet d'entree) ;
 * - caissier_id pointe desormais sur users (V1 mat_admin = compte connecte :
 *   le fondateur ou un comptable peut encaisser sans fiche personnel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reglements', function (Blueprint $table) {
            $table->dropForeign(['caissier_id']);
        });
        Schema::table('reglements', function (Blueprint $table) {
            $table->foreign('caissier_id')->references('id')->on('users');
            $table->foreignId('inscription_id')->nullable()->after('annee_scolaire_id')->constrained('inscriptions')->nullOnDelete();
            $table->unsignedTinyInteger('numero_versement')->nullable()->after('numero_recu');
            $table->date('date_expiration')->nullable()->after('date_paiement');
        });
    }

    public function down(): void
    {
        Schema::table('reglements', function (Blueprint $table) {
            $table->dropForeign(['caissier_id']);
            $table->dropConstrainedForeignId('inscription_id');
            $table->dropColumn(['numero_versement', 'date_expiration']);
        });
        Schema::table('reglements', function (Blueprint $table) {
            $table->foreign('caissier_id')->references('id')->on('personnels')->cascadeOnDelete();
        });
    }
};
