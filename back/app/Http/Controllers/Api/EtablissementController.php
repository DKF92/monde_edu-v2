<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EtablissementResource;
use App\Models\Etablissement;
use Illuminate\Http\Request;

class EtablissementController extends Controller
{
    /**
     * Annuaire public des etablissements actifs, utilise par l'ecran de
     * selection affiche AVANT la connexion (l'utilisateur choisit son ecole,
     * puis se connecte). Ne necessite pas d'authentification.
     */
    public function index(Request $request)
    {
        $etablissements = Etablissement::query()
            ->where('statut', 'actif')
            ->when($request->string('recherche')->isNotEmpty(), function ($query) use ($request) {
                $recherche = $request->string('recherche')->value();
                $query->where(function ($q) use ($recherche) {
                    $q->where('nom', 'like', "%{$recherche}%")
                        ->orWhere('ville', 'like', "%{$recherche}%")
                        ->orWhere('code', 'like', "%{$recherche}%");
                });
            })
            ->orderBy('nom')
            ->get();

        return EtablissementResource::collection($etablissements);
    }
}
