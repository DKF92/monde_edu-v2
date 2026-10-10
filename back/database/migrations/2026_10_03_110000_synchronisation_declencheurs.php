<?php

use App\Support\Synchro\Declencheurs;
use App\Support\Synchro\Synchroniseur;
use Illuminate\Database\Migrations\Migration;

/**
 * Role du serveur (sync_etat) et declencheurs du journal de synchronisation
 * sur toutes les tables suivies (recrees ensuite apres chaque migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Synchroniseur::noter('role', config('serveur.role'));
        Declencheurs::creer();
    }

    public function down(): void
    {
        Declencheurs::supprimer();
    }
};
