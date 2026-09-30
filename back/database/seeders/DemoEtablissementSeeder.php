<?php

namespace Database\Seeders;

use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Etablissement;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\Periode;
use App\Models\User;
use App\Services\EtablissementProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Donnees de demonstration (rejouable sans doublons). Couvre les cas du
 * parcours de connexion :
 * - fondateur@demo...   : 2 etablissements, 1 poste (Fondateur) dans chacun ;
 * - multiposte@demo...  : 1 etablissement, 2 postes (Professeur + Caissier).
 * Mot de passe de tous les comptes : "password".
 */
class DemoEtablissementSeeder extends Seeder
{
    public function run(EtablissementProvisioningService $provisioning): void
    {
        $principal = $this->etablissement($provisioning, [
            'code' => 'DEMO001',
            'nom' => 'Groupe Scolaire Demo',
            'sigle' => 'GSD',
            'type_etablissement' => 'college_lycee',
            'ville' => 'Abidjan',
        ]);

        $secondaire = $this->etablissement($provisioning, [
            'code' => 'DEMO002',
            'nom' => 'College Demo Plateau',
            'sigle' => 'CDP',
            'type_etablissement' => 'college_lycee',
            'ville' => 'Bouake',
        ]);

        $fondateur = $this->utilisateur('fondateur@demo.mondeeducatif.ci', 'Fondateur', 'Demo', $principal);
        $this->attribuer($fondateur, $principal, ['Fondateur', 'Super admin'], defaut: true);
        $this->attribuer($fondateur, $secondaire, ['Fondateur', 'Super admin']);

        $multiposte = $this->utilisateur('multiposte@demo.mondeeducatif.ci', 'Kouassi', 'Awa', $principal);
        $this->attribuer($multiposte, $principal, ['Professeur', 'Caissier'], defaut: true);
    }

    private function etablissement(EtablissementProvisioningService $provisioning, array $donnees): Etablissement
    {
        $etablissement = Etablissement::firstOrCreate(
            ['code' => $donnees['code']],
            $donnees + ['pays' => "Côte d'Ivoire", 'statut' => 'actif']
        );

        $provisioning->provisionner($etablissement);
        $this->matieres($etablissement);

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

        return $etablissement;
    }

    /** Matieres courantes du secondaire ivoirien. */
    private function matieres(Etablissement $etablissement): void
    {
        $matieres = [
            ['FR', 'Français', 'LITTERAIRE'], ['ANG', 'Anglais', 'LITTERAIRE'],
            ['ESP', 'Espagnol', 'LITTERAIRE'], ['ALL', 'Allemand', 'LITTERAIRE'],
            ['HG', 'Histoire-Géographie', 'LITTERAIRE'], ['PHILO', 'Philosophie', 'LITTERAIRE'],
            ['EDHC', 'EDHC', 'LITTERAIRE'], ['MATH', 'Mathématiques', 'SCIENTIFIQUE'],
            ['PC', 'Physique-Chimie', 'SCIENTIFIQUE'], ['SVT', 'SVT', 'SCIENTIFIQUE'],
            ['INFO', 'Informatique', 'SCIENTIFIQUE'], ['EPS', 'EPS', 'AUTRES'],
            ['AP', 'Arts plastiques', 'AUTRES'],
        ];

        foreach ($matieres as [$code, $libelle, $groupe]) {
            Matiere::withoutGlobalScopes()->firstOrCreate(
                ['etablissement_id' => $etablissement->id, 'code' => $code],
                ['libelle' => $libelle, 'groupe_bulletin' => $groupe]
            );
        }
    }

    private function utilisateur(string $email, string $nom, string $prenoms, Etablissement $principal): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'etablissement_id' => $principal->id,
                'name' => $nom,
                'prenoms' => $prenoms,
                'password' => Hash::make('password'),
                'statut' => 'actif',
                'doit_changer_mot_de_passe' => false,
            ]
        );
    }

    /** Rattache l'utilisateur a l'etablissement avec ses postes (roles). */
    private function attribuer(User $user, Etablissement $etablissement, array $postes, bool $defaut = false): void
    {
        $etablissement->utilisateurs()->syncWithoutDetaching([
            $user->id => ['is_defaut' => $defaut],
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($etablissement->id);
        $user->unsetRelation('roles')->syncRoles($postes);
    }
}
