<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Forces the request to be treated as a JSON API request.
 *
 * Laravel uses the request "Accept" header to decide whether to render route
 * errors (validation, authentication, authorization, ...) as JSON. This
 * middleware guarantees every request under the API group is rendered as JSON,
 * regardless of the client's Accept header.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
