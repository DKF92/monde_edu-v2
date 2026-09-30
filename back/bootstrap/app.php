<?php

use App\Http\Middleware\ExigerMotDePasseDefinitif;
use App\Http\Middleware\ResolveContexteScolaire;
use App\Http\Middleware\ResolveEtablissement;
use App\Http\Middleware\ResolvePoste;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'etablissement' => ResolveEtablissement::class,
            'contexte-scolaire' => ResolveContexteScolaire::class,
            'poste' => ResolvePoste::class,
            'mot-de-passe-definitif' => ExigerMotDePasseDefinitif::class,
        ]);

        // API pure: jamais de redirection vers une route web "login" inexistante,
        // toujours une reponse JSON 401 sur les requetes non authentifiees.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
