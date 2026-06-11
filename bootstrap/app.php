<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum : middleware stateful pour les routes SPA (cookie de session)
        $middleware->statefulApi();

        // On s'assure que la session est démarrée pour toutes les routes API
        // car LoginController l'utilise pour régénérer la session.
        $middleware->appendToGroup('api', [
            StartSession::class,
        ]);

        // Sans cela, une requête non-JSON non authentifiée (ex. EventSource SSE)
        // tenterait un redirect vers la route nommée 'login' qui n'existe pas dans
        // cette app API-only, résultant en une RouteNotFoundException (500).
        // Retourner null force shouldRenderJsonWhen à produire un 401 JSON propre.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
