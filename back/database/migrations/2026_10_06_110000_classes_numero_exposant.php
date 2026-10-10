<?php

use App\Support\NomClasse;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Noms des classes refaits a partir du libelle du niveau, numero en exposant
 * (demande du 06/10/2026) : « TLE A2 3 » -> « TleA2³ », « 6EME 12 » ->
 * « 6eme¹² » ; numerotation en lettres : « 6eme A ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $niveaux = DB::table('niveaux')->pluck('libelle', 'id');
        foreach (DB::table('classes')->get(['id', 'libelle', 'niveau_id']) as $c) {
            $niveau = (string) ($niveaux[$c->niveau_id] ?? '');
            $libelle = NomClasse::composer($niveau, NomClasse::nom($c->libelle, $niveau));
            if ($niveau !== '' && $libelle !== $c->libelle) {
                DB::table('classes')->where('id', $c->id)->update(['libelle' => $libelle]);
            }
        }
    }

    public function down(): void
    {
        $niveaux = DB::table('niveaux')->pluck('libelle', 'id');
        foreach (DB::table('classes')->get(['id', 'libelle', 'niveau_id']) as $c) {
            $niveau = (string) ($niveaux[$c->niveau_id] ?? '');
            $nom = NomClasse::nom($c->libelle, $niveau);
            DB::table('classes')->where('id', $c->id)->update(['libelle' => mb_strtoupper(trim($niveau.' '.$nom))]);
        }
    }
};
