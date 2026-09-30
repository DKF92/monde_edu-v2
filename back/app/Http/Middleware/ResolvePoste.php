<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint les droits de la requete au poste (role) choisi par
 * l'utilisateur a la connexion, lu dans l'en-tete "X-Poste-Id".
 *
 * Un meme compte peut cumuler plusieurs postes dans un etablissement (ex:
 * Professeur + Caissier). Comme en V1 ou le menu dependait du poste, il
 * travaille avec UN poste a la fois : toutes les verifications de
 * permission ($user->can(...)) ne voient alors que ce role. Sans en-tete,
 * l'utilisateur garde l'ensemble de ses roles (ecrans de choix du contexte).
 *
 * A executer APRES ResolveEtablissement (les roles sont propres a chaque
 * etablissement via le "team id" de spatie/laravel-permission).
 */
class ResolvePoste
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $request->hasHeader('X-Poste-Id')) {
            $poste = $user->roles()
                ->where('roles.id', (int) $request->header('X-Poste-Id'))
                ->first();

            if (! $poste) {
                abort(403, "Ce poste n'est pas attribue a votre compte dans cet etablissement.");
            }

            if (! $poste->is_active) {
                abort(403, "Ce poste a ete desactive. Choisissez un autre poste.");
            }

            // spatie/laravel-permission lit la relation "roles" chargee : la
            // remplacer suffit pour que hasRole/can ne considerent que ce poste.
            $user->setRelation('roles', collect([$poste]));
        }

        return $next($request);
    }
}
