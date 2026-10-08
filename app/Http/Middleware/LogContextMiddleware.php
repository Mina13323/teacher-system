<?php

namespace App\Http\Middleware;

use App\Services\Observability\ErrorCategory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 5 §42 & Production Observability Middleware.
 *
 * Tracks request execution duration, database query metrics (count, total DB time,
 * slowest query), and safe structured context (request_id, route, method, status,
 * user_id, attempt_id).
 *
 * CRITICAL SAFETY: Never logs passwords, tokens, cookies, auth headers, or request bodies.
 */
class LogContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $requestId = (string) Str::uuid();

        $queryCount = 0;
        $totalDbTime = 0.0;
        $slowestQueryTime = 0.0;

        DB::listen(function ($query) use (&$queryCount, &$totalDbTime, &$slowestQueryTime) {
            $queryCount++;
            $time = (float) $query->time; // in milliseconds in Laravel
            $totalDbTime += $time;
            if ($time > $slowestQueryTime) {
                $slowestQueryTime = $time;
            }
        });

        $initialContext = [
            'request_id' => $requestId,
            'route' => $request->route()?->getName() ?? $request->path(),
            'method' => $request->method(),
            'attempt_id' => (fn ($a) => is_object($a) ? $a->getKey() : $a)($request->route('attempt')),
        ];

        Log::withContext(array_filter($initialContext, fn ($v) => $v !== null));

        try {
            $response = $next($request);
        } finally {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $user = $request->user();
            $attempt = $request->route('attempt');
            $attemptId = is_object($attempt) ? $attempt->getKey() : $attempt;
            $status = isset($response) ? $response->getStatusCode() : 500;

            $metrics = [
                'request_id' => $requestId,
                'route' => $request->route()?->getName() ?? $request->path(),
                'method' => $request->method(),
                'status' => $status,
                'duration_ms' => $durationMs,
                'db_queries' => $queryCount,
                'db_duration_ms' => round($totalDbTime, 2),
                'slowest_query_ms' => round($slowestQueryTime, 2),
                'user_id' => $user?->getAuthIdentifier(),
                'attempt_id' => $attemptId,
                'error_category' => isset($response) ? ErrorCategory::forResponse($response) : null,
            ];

            $metrics = array_filter($metrics, fn ($v) => $v !== null);

            // Re-apply full context including user/metrics for any downstream error handlers
            Log::withContext($metrics);

            // Structured request performance logging:
            // Log as warning if request duration or query time crosses threshold
            if ($durationMs >= 2000 || $slowestQueryTime >= 500) {
                Log::warning('api.request.slow', $metrics);
            } else {
                Log::info('api.request.completed', $metrics);
            }
        }

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Request-Duration-Ms', (string) $durationMs);
        $response->headers->set('X-DB-Queries', (string) $queryCount);
        $response->headers->set('X-DB-Duration-Ms', (string) round($totalDbTime, 2));

        return $response;
    }
}
