<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte avec un mot de passe provisoire (creation ou reinitialisation par
 * l'administration) : tant que l'utilisateur n'a pas choisi son propre mot
 * de passe (POST /mot-de-passe), l'API refuse tout le reste, sauf /me et
 * /logout.
 */
class ExigerMotDePasseDefinitif
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->doit_changer_mot_de_passe && ! $request->is('api/me', 'api/logout')) {
            return response()->json([
                'message' => 'Choisissez votre mot de passe avant de continuer.',
                'code' => 'mot_de_passe_a_changer',
            ], 403);
        }

        return $next($request);
    }
}
