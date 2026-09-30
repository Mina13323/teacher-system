<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 5 §42 — Structured log/error context.
 *
 * Every request carries a `request_id` plus safe actor/route context into the
 * logs, so failures can be traced end-to-end (request → route → role → attempt
 * when the route has one). Never logs credentials, tokens or answer content —
 * only identifiers and route metadata.
 */
class LogContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) (Str::uuid());

        $context = [
            'request_id' => $requestId,
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'user_role' => $request->user()?->roles?->first()?->name,
            'attempt_id' => $request->route('attempt')?->getKey() ?? $request->route('attempt'),
        ];
        $context = array_filter($context, fn ($v) => $v !== null);

        // Shared for every log line emitted during this request.
        \Illuminate\Support\Facades\Log::withContext($context);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
