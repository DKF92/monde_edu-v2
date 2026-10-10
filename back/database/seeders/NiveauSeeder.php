<?php

namespace Database\Seeders;

use App\Models\Niveau;
use Illuminate\Database\Seeder;

class NiveauSeeder extends Seeder
{
    public const SERIES = ['2NDE' => ['A', 'C'], '1ERE' => ['A', 'C', 'D'], 'TLE' => ['A1', 'A2', 'C', 'D']];

    /**
     * Referentiel national des niveaux, commun a tous les etablissements
     * (maternelle -> primaire -> college -> lycee). Execute une seule fois,
     * independamment du nombre d'etablissements crees ensuite.
     */
    public function run(): void
    {
        $niveaux = [
            ['code' => 'PS', 'libelle' => 'Petite Section', 'cycle' => 'maternelle', 'ordre' => 1],
            ['code' => 'MS', 'libelle' => 'Moyenne Section', 'cycle' => 'maternelle', 'ordre' => 2],
            ['code' => 'GS', 'libelle' => 'Grande Section', 'cycle' => 'maternelle', 'ordre' => 3],

            ['code' => 'CP1', 'libelle' => 'CP1', 'cycle' => 'primaire', 'ordre' => 4],
            ['code' => 'CP2', 'libelle' => 'CP2', 'cycle' => 'primaire', 'ordre' => 5],
            ['code' => 'CE1', 'libelle' => 'CE1', 'cycle' => 'primaire', 'ordre' => 6],
            ['code' => 'CE2', 'libelle' => 'CE2', 'cycle' => 'primaire', 'ordre' => 7],
            ['code' => 'CM1', 'libelle' => 'CM1', 'cycle' => 'primaire', 'ordre' => 8],
            ['code' => 'CM2', 'libelle' => 'CM2', 'cycle' => 'primaire', 'ordre' => 9],

            ['code' => '6EME', 'libelle' => '6eme', 'cycle' => 'college', 'ordre' => 10],
            ['code' => '5EME', 'libelle' => '5eme', 'cycle' => 'college', 'ordre' => 11],
            ['code' => '4EME', 'libelle' => '4eme', 'cycle' => 'college', 'ordre' => 12],
            ['code' => '3EME', 'libelle' => '3eme', 'cycle' => 'college', 'ordre' => 13],

            ['code' => '2NDE', 'libelle' => '2nde', 'cycle' => 'lycee', 'ordre' => 14],
            ['code' => '1ERE', 'libelle' => '1ere', 'cycle' => 'lycee', 'ordre' => 15],
            ['code' => 'TLE', 'libelle' => 'Tle', 'cycle' => 'lycee', 'ordre' => 16],
        ];

        foreach ($niveaux as $data) {
            Niveau::firstOrCreate(['code' => $data['code']], $data);
        }

        // Niveaux avec serie du 2nd cycle (modele V1, decision E1), rattaches a leur niveau general.
        foreach (self::SERIES as $code => $series) {
            $general = Niveau::where('code', $code)->first();
            foreach ($series as $serie) {
                Niveau::firstOrCreate(['code' => $code.$serie], [
                    // Libelles V1 : 2ndeA, 1ereD, TleA2 (niveau general + serie, sans espace).
                    'niveau_general_id' => $general->id, 'libelle' => $general->libelle.$serie,
                    'cycle' => $general->cycle, 'ordre' => $general->ordre,
                ]);
            }
        }
    }
}
