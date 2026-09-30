<?php

namespace App\Services;

use App\Models\Etablissement;
use App\Models\Poste;
use App\Models\TypeFrais;
use App\Models\TypeReduction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cree les donnees standard d'un etablissement qui vient d'etre cree :
 * - les postes (voir config/roles.php), scopes via le systeme "teams" de
 *   spatie/laravel-permission ;
 * - les types de frais (colonnes de V1 cout_formation) ;
 * - les types de reduction (V1 : "reduction" et "cas").
 *
 * Rejouable : un poste deja present garde ses droits et son libelle (ils ont
 * pu etre modifies par l'ecole dans l'administration des postes).
 */
class EtablissementProvisioningService
{
    /**
     * Types de frais de la V1 (cout_formation / inscription), dans l'ordre
     * d'imputation d'un paiement : annexes d'abord, puis inscription, puis
     * scolarite (V1 reglementController).
     */
    public const TYPES_FRAIS = [
        ['code' => 'FRAIS_ANNEXES', 'libelle' => 'Frais annexes', 'nature' => 'annexe'],
        ['code' => 'DROIT_EXAMEN', 'libelle' => 'Droit d\'examen', 'nature' => 'annexe'],
        ['code' => 'LIVRET_SCOLAIRE', 'libelle' => 'Livret scolaire', 'nature' => 'annexe'],
        ['code' => 'PHOTO', 'libelle' => 'Photo', 'nature' => 'annexe'],
        ['code' => 'TEESHIRT', 'libelle' => 'Tee-shirt', 'nature' => 'annexe'],
        ['code' => 'TENUE_EPS', 'libelle' => 'Tenue EPS', 'nature' => 'annexe'],
        ['code' => 'MACARON', 'libelle' => 'Macaron', 'nature' => 'annexe'],
        ['code' => 'CARTE_ACCES', 'libelle' => 'Carte d\'accès', 'nature' => 'annexe'],
        ['code' => 'INFORMATIQUE', 'libelle' => 'Informatique', 'nature' => 'annexe'],
        ['code' => 'LOGICIEL', 'libelle' => 'Logiciel', 'nature' => 'annexe'],
        ['code' => 'CRAIE', 'libelle' => 'Craie', 'nature' => 'en_nature'],
        ['code' => 'RAME', 'libelle' => 'Rame de papier', 'nature' => 'en_nature'],
        ['code' => 'INSCRIPTION', 'libelle' => 'Frais d\'inscription', 'nature' => 'inscription'],
        ['code' => 'SCOLARITE', 'libelle' => 'Scolarité', 'nature' => 'scolarite'],
    ];

    public function provisionner(Etablissement $etablissement): void
    {
        $this->provisionRoles($etablissement);
        $this->provisionTypesFrais($etablissement);
        $this->provisionTypesReductions($etablissement);
    }

    public function provisionRoles(Etablissement $etablissement): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($etablissement->id);

        foreach (config('roles.roles') as $nom => $definition) {
            $poste = Poste::where('etablissement_id', $etablissement->id)
                ->where(fn ($q) => $q->where('code', $definition['code'])->orWhere('name', $nom))
                ->first();

            if ($poste) {
                // Postes crees avant l'ajout des colonnes : on complete sans
                // toucher au libelle ni aux droits choisis par l'ecole.
                if (! $poste->code) {
                    $poste->update($this->attributs($definition));
                }

                continue;
            }

            $poste = Poste::create([
                'name' => $nom,
                'guard_name' => 'web',
                'etablissement_id' => $etablissement->id,
            ] + $this->attributs($definition));

            $poste->syncPermissions($this->permissions($definition['permissions']));
        }
    }

    public function provisionTypesFrais(Etablissement $etablissement): void
    {
        foreach (self::TYPES_FRAIS as $ordre => $type) {
            TypeFrais::withoutGlobalScopes()->firstOrCreate(
                ['etablissement_id' => $etablissement->id, 'code' => $type['code']],
                $type + ['ordre' => $ordre + 1, 'is_obligatoire' => true, 'is_active' => true, 'applicable_affecte' => $type['nature'] !== 'scolarite', 'applicable_non_affecte' => true]
            );
        }
    }

    public function provisionTypesReductions(Etablissement $etablissement): void
    {
        foreach (TypeReduction::STANDARDS as $type) {
            TypeReduction::withoutGlobalScopes()->firstOrCreate(
                ['etablissement_id' => $etablissement->id, 'code' => $type['code']],
                $type
            );
        }
    }

    /** Colonnes descriptives d'un poste standard. */
    private function attributs(array $definition): array
    {
        return [
            'code' => $definition['code'],
            'description' => $definition['description'] ?? null,
            'is_active' => true,
            'est_sensible' => $definition['sensible'] ?? false,
            'lie_niveaux' => $definition['niveaux'] ?? false,
            'lie_matieres' => $definition['matieres'] ?? false,
        ];
    }

    /** "*" = toutes les permissions sauf les reservees (a citer explicitement). */
    private function permissions(array $noms)
    {
        if (! in_array('*', $noms, true)) {
            return $noms;
        }

        $reservees = config('roles.permissions_reservees', []);
        $explicites = array_diff($noms, ['*']);

        return Permission::where('guard_name', 'web')
            ->get()
            ->filter(fn (Permission $p) => ! in_array($p->name, $reservees, true) || in_array($p->name, $explicites, true))
            ->values();
    }
}
