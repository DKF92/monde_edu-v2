<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Models\AnneeScolaire;
use App\Models\Etablissement;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use App\Models\Poste;

class ContexteController extends Controller
{
    /**
     * Tout ce qu'il faut a l'ecran "espace de travail" (juste apres la
     * connexion) pour l'etablissement demande (en-tete X-Etablissement-Id,
     * dont l'acces est deja verifie par le middleware "etablissement") :
     * - ses annees scolaires, avec leurs trimestres/semestres ;
     * - les postes (roles) de l'utilisateur DANS cet etablissement, avec
     *   leurs permissions (le menu est construit a partir du poste choisi,
     *   comme en V1 ou le menu dependait du poste du compte).
     */
    public function index(Request $request, TenantContext $tenant)
    {
        $annees = AnneeScolaire::with(['periodes' => fn ($q) => $q->orderBy('type_decoupage')->orderBy('numero')])
            ->orderByDesc('libelle')
            ->get()
            ->map(fn (AnneeScolaire $annee) => [
                'id' => $annee->id,
                'libelle' => $annee->libelle,
                'is_active' => $annee->is_active,
                'is_cloturee' => $annee->is_cloturee,
                'periodes' => $annee->periodes->map(fn ($p) => [
                    'id' => $p->id,
                    'annee_scolaire_id' => $p->annee_scolaire_id,
                    'type_decoupage' => $p->type_decoupage,
                    'numero' => $p->numero,
                    'libelle' => $p->libelle,
                    'is_active' => $p->is_active,
                    'is_cloturee' => $p->is_cloturee,
                ])->values(),
            ]);

        // Requete explicite (et non $user->roles) : le middleware "poste" a pu
        // restreindre la relation chargee au seul poste de la requete en cours.
        $postes = $request->user()->roles()
            ->where('is_active', true)
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Poste $role) => [
                'id' => $role->id,
                'nom' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        return response()->json([
            'etablissement' => new EtablissementResource(Etablissement::findOrFail($tenant->id())),
            'annees' => $annees,
            'postes' => $postes,
        ]);
    }
}
