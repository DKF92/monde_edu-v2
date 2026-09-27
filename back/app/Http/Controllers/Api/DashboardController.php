<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Reglement;
use App\Support\TenantContext;

class DashboardController extends Controller
{
    public function index(TenantContext $tenant)
    {
        // Priorite a l'annee scolaire choisie par l'utilisateur (en-tete
        // X-Annee-Scolaire-Id) ; a defaut, l'annee marquee active sert de repli.
        $annee = $tenant->anneeScolaireId()
            ? AnneeScolaire::find($tenant->anneeScolaireId())
            : AnneeScolaire::where('is_active', true)->first();

        return response()->json([
            'annee_scolaire_id' => $annee?->id,
            'annee_scolaire_active' => $annee?->libelle,
            'effectif_eleves' => Eleve::where('statut', 'actif')->count(),
            'nombre_classes' => $annee
                ? Classe::where('annee_scolaire_id', $annee->id)->count()
                : 0,
            'encaissements_du_jour' => (float) Reglement::whereDate('date_paiement', today())->sum('montant_total'),
            'encaissements_du_mois' => (float) Reglement::whereMonth('date_paiement', now()->month)
                ->whereYear('date_paiement', now()->year)
                ->sum('montant_total'),
        ]);
    }
}
