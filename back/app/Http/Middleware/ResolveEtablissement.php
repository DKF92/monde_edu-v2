<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Determine l'etablissement (tenant) pour la requete API courante et le rend
 * disponible via TenantContext (scope automatique des modeles Eloquent) et
 * via le "team id" de spatie/laravel-permission (roles/permissions scopes
 * par etablissement).
 *
 * L'etablissement cible est lu dans l'en-tete "X-Etablissement-Id". A defaut,
 * l'etablissement principal de l'utilisateur connecte est utilise. Un
 * utilisateur ne peut selectionner qu'un etablissement auquel il a acces,
 * c'est a dire son etablissement principal ou l'un de ceux lies via la
 * table pivot etablissement_user (ex: fondateur d'un groupe scolaire).
 */
class ResolveEtablissement
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $etablissementId = $this->resolveEtablissementId($request, $user);

            if ($etablissementId) {
                app(TenantContext::class)->set($etablissementId);
                app(PermissionRegistrar::class)->setPermissionsTeamId($etablissementId);
            }
        }

        return $next($request);
    }

    private function resolveEtablissementId(Request $request, $user): ?int
    {
        $requested = $request->header('X-Etablissement-Id');

        if ($requested) {
            $requested = (int) $requested;

            $aAcces = $requested === $user->etablissement_id
                || $user->etablissements()->where('etablissements.id', $requested)->exists();

            if (! $aAcces) {
                abort(403, "Vous n'avez pas acces a cet etablissement.");
            }

            return $requested;
        }

        return $user->etablissement_id;
    }
}
