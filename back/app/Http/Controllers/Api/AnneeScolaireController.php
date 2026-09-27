<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnneeScolaireResource;
use App\Models\AnneeScolaire;

class AnneeScolaireController extends Controller
{
    /**
     * Annees scolaires de l'etablissement courant (scope automatique via
     * BelongsToEtablissement + TenantContext deja positionne par le
     * middleware "etablissement").
     */
    public function index()
    {
        $annees = AnneeScolaire::orderByDesc('libelle')->get();

        return AnneeScolaireResource::collection($annees);
    }
}
