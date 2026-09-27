<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PeriodeResource;
use App\Models\Periode;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PeriodeController extends Controller
{
    /**
     * Periodes (trimestres/semestres) de l'annee scolaire demandee, ou de
     * l'annee scolaire courante (en-tete X-Annee-Scolaire-Id) a defaut.
     */
    public function index(Request $request, TenantContext $tenant)
    {
        $anneeScolaireId = $request->integer('annee_scolaire_id') ?: $tenant->anneeScolaireId();

        if (! $anneeScolaireId) {
            return response()->json(['message' => "Precisez annee_scolaire_id ou l'en-tete X-Annee-Scolaire-Id."], 422);
        }

        $periodes = Periode::where('annee_scolaire_id', $anneeScolaireId)
            ->orderBy('numero')
            ->get();

        return PeriodeResource::collection($periodes);
    }
}
