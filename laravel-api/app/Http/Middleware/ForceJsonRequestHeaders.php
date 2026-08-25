<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures every /api/* request negotiates JSON even when the client omits
 * an Accept header, so auth failures return the legacy JSON envelope
 * instead of a redirect attempt.
 */
class ForceJsonRequestHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
