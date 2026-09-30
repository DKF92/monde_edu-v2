<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * inscriptions.statut reprend les valeurs V1 de inscription.inscr_termine :
 * 0 = inscription creee sans classe, 1 = classe choisie, 2 = au moins un
 * paiement, 3 = solde. L'annulation supprime l'inscription (V1 suprInscrid) :
 * plus de valeur "annulee".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('inscriptions')->where('statut', 'annulee')->delete();
        DB::statement("ALTER TABLE inscriptions MODIFY statut VARCHAR(20) NOT NULL DEFAULT '0'");
        DB::statement("UPDATE inscriptions SET statut = CASE statut
            WHEN 'en_attente' THEN '1' WHEN 'inscrit' THEN '2' WHEN 'solde' THEN '3' ELSE '0' END");
        DB::statement('ALTER TABLE inscriptions MODIFY statut TINYINT UNSIGNED NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inscriptions MODIFY statut VARCHAR(20) NOT NULL DEFAULT 'sans_classe'");
        DB::statement("UPDATE inscriptions SET statut = CASE statut
            WHEN '1' THEN 'en_attente' WHEN '2' THEN 'inscrit' WHEN '3' THEN 'solde' ELSE 'sans_classe' END");
        DB::statement("ALTER TABLE inscriptions MODIFY statut ENUM('sans_classe','en_attente','inscrit','solde','annulee') NOT NULL DEFAULT 'sans_classe'");
    }
};
