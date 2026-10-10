<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sur le serveur EN LIGNE (SERVEUR_ROLE=en_ligne), refuse toute ecriture qui
 * n'est pas pedagogique (liste config/serveur.php) : le serveur de l'ecole fait
 * foi pour les inscriptions, paiements, classes, comptes... Les lectures
 * passent toujours. Sans effet sur le serveur local.
 */
class EcrituresServeurEnLigne
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('serveur.role') !== 'en_ligne' || ! $request->is('api/*') || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $chemin = preg_replace('#^api/#', '', trim($request->path(), '/'));
        foreach (config('serveur.ecritures_en_ligne', []) as $regle) {
            [$methode, $modele] = explode(' ', $regle, 2);
            if ($methode === $request->method() && preg_match(self::motif($modele), $chemin)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Cette opération se fait depuis l\'école (serveur local) : le serveur en ligne ne permet que la pédagogie (notes, absences, conduite, rapports) et l\'assistant.',
            'code' => 'ecriture_locale_seulement',
        ], 403);
    }

    private static function motif(string $modele): string
    {
        $motif = preg_quote($modele, '#');
        $motif = str_replace([preg_quote('{id}', '#'), preg_quote('*', '#')], ['\d+', '[^/]+'], $motif);

        return '#^'.$motif.'$#';
    }
}
