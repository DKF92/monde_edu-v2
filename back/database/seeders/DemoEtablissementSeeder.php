<?php

namespace Database\Seeders;

use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Niveau;
use App\Models\Periode;
use App\Models\User;
use App\Services\EtablissementProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class DemoEtablissementSeeder extends Seeder
{
    public function run(EtablissementProvisioningService $provisioning): void
    {
        $etablissement = Etablissement::firstOrCreate(
            ['code' => 'DEMO001'],
            [
                'nom' => "Groupe Scolaire Demo",
                'sigle' => 'GSD',
                'type_etablissement' => 'college_lycee',
                'ville' => 'Abidjan',
                'pays' => "Côte d'Ivoire",
                'statut' => 'actif',
            ]
        );

        $provisioning->provisionRoles($etablissement);

        $annee = AnneeScolaire::firstOrCreate(
            ['etablissement_id' => $etablissement->id, 'libelle' => '2025-2026'],
            ['is_active' => true]
        );

        $trimestres = [
            ['numero' => 1, 'libelle' => '1er trimestre'],
            ['numero' => 2, 'libelle' => '2eme trimestre'],
            ['numero' => 3, 'libelle' => '3eme trimestre'],
        ];

        foreach ($trimestres as $data) {
            Periode::firstOrCreate(
                [
                    'etablissement_id' => $etablissement->id,
                    'annee_scolaire_id' => $annee->id,
                    'type_decoupage' => 'trimestre',
                    'numero' => $data['numero'],
                ],
                ['libelle' => $data['libelle'], 'is_active' => $data['numero'] === 1]
            );
        }

        // Les niveaux sont un referentiel global (voir NiveauSeeder) : on ouvre
        // ici seulement les classes de cet etablissement pour les niveaux
        // college/lycee qu'il propose.
        $codesNiveauxOuverts = ['6EME', '5EME', '4EME', '3EME', '2NDE', '1ERE', 'TLE'];

        foreach (Niveau::whereIn('code', $codesNiveauxOuverts)->get() as $niveau) {
            Classe::firstOrCreate([
                'etablissement_id' => $etablissement->id,
                'annee_scolaire_id' => $annee->id,
                'niveau_id' => $niveau->id,
                'libelle' => $niveau->code.' A',
            ]);
        }

        $fondateur = User::firstOrCreate(
            ['email' => 'fondateur@demo.mondeeducatif.ci'],
            [
                'etablissement_id' => $etablissement->id,
                'name' => 'Fondateur',
                'prenoms' => 'Demo',
                'password' => Hash::make('password'),
                'statut' => 'actif',
                'doit_changer_mot_de_passe' => false,
            ]
        );

        $etablissement->utilisateurs()->syncWithoutDetaching([
            $fondateur->id => ['is_defaut' => true],
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($etablissement->id);
        $fondateur->syncRoles(['Fondateur']);
    }
}
