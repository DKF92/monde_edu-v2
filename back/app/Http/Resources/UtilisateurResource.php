<?php

namespace App\Http\Resources;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Utilisateur tel que vu depuis l'ecran d'administration de l'etablissement
 * courant : ses postes sont ceux qu'il occupe DANS cet etablissement.
 */
class UtilisateurResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $personnel = $this->personnel;

        return [
            'id' => $this->id,
            'matricule' => $this->matricule,
            'nom' => $this->name,
            'prenoms' => $this->prenoms,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'sexe' => $this->sexe,
            'statut' => $this->statut,
            'doit_changer_mot_de_passe' => $this->doit_changer_mot_de_passe,
            'derniere_connexion_at' => $this->derniere_connexion_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'est_moi' => $this->id === $request->user()->id,
            'etablissement_principal' => $this->etablissement_id === app(TenantContext::class)->id(),
            'postes' => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'nom' => $role->name,
                'est_sensible' => (bool) $role->est_sensible,
            ])->values(),
            // Compte avec un poste protege : modifiable seulement par le Super admin
            // (sauf sa propre fiche).
            'modifiable' => $this->id === $request->user()->id
                || ! $this->roles->contains('est_sensible', true)
                || $request->user()->can('utilisateurs.gerer_sensibles'),
            'niveaux' => $personnel?->relationLoaded('niveaux')
                ? $personnel->niveaux->map(fn ($n) => ['id' => $n->id, 'libelle' => $n->libelle])->values()
                : [],
            'matieres' => $personnel?->relationLoaded('matieres')
                ? $personnel->matieres->map(fn ($m) => ['id' => $m->id, 'libelle' => $m->libelle])->values()
                : [],
            'personnel' => $personnel ? [
                'diplome' => $personnel->diplome,
                'statut_contrat' => $personnel->statut_contrat,
                'date_embauche' => $personnel->date_embauche?->toDateString(),
                'nationalite' => $personnel->nationalite,
                'quartier' => $personnel->quartier,
            ] : null,
        ];
    }
}
