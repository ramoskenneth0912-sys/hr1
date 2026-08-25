<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // All /api/* traffic speaks JSON — auth failures must be 401 envelopes,
        // never redirects (there is intentionally no Laravel 'login' route).
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonRequestHeaders::class,
        ]);

        // Safe target for any non-API guest redirect (no named 'login' route).
        $middleware->redirectGuestsTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mirror the legacy /api/v1 JSON envelope for auth failures:
        // {"success":false,"message":"Authentication required.","errors":{}}
        $exceptions->render(function (AuthenticationException $e, Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required.',
                    'errors' => new \stdClass(),
                ], 401, ['WWW-Authenticate' => 'Bearer realm="HR1 API"']);
            }

            return redirect('/');
        });

        $exceptions->render(function (ValidationException $e, Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors() ?: new \stdClass(),
                ], 422);
            }

            return null;
        });
    })->create();
