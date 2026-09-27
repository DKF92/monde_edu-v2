<?php

namespace App\Http\Middleware;

use App\Models\AnneeScolaire;
use App\Models\Periode;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Determine l'annee scolaire et, le cas echeant, la periode (trimestre ou
 * semestre) sur laquelle l'utilisateur travaille, a partir des en-tetes
 * "X-Annee-Scolaire-Id" / "X-Periode-Id". A executer APRES ResolveEtablissement
 * (dont depend le TenantContext deja positionne sur l'etablissement courant).
 *
 * Contrairement a l'etablissement, l'absence de ces en-tetes n'est pas une
 * erreur : de nombreuses routes (ex: liste des annees scolaires elles-memes)
 * n'en ont pas besoin. Les controleurs qui en ont besoin verifient
 * TenantContext::anneeScolaireId()/periodeId() eux-memes.
 */
class ResolveContexteScolaire
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class);

        if ($request->hasHeader('X-Annee-Scolaire-Id')) {
            $anneeScolaireId = (int) $request->header('X-Annee-Scolaire-Id');

            $appartient = AnneeScolaire::where('id', $anneeScolaireId)
                ->where('etablissement_id', $tenant->id())
                ->exists();

            if (! $appartient) {
                abort(403, "Annee scolaire invalide pour cet etablissement.");
            }

            $tenant->setAnneeScolaire($anneeScolaireId);
        }

        if ($request->hasHeader('X-Periode-Id')) {
            $periodeId = (int) $request->header('X-Periode-Id');

            $appartient = Periode::where('id', $periodeId)
                ->where('etablissement_id', $tenant->id())
                ->when($tenant->anneeScolaireId(), fn ($q) => $q->where('annee_scolaire_id', $tenant->anneeScolaireId()))
                ->exists();

            if (! $appartient) {
                abort(403, "Periode invalide pour cet etablissement/annee.");
            }

            $tenant->setPeriode($periodeId);
        }

        return $next($request);
    }
}
