<?php

namespace App\Http\Middleware;

use App\Services\Observability\ErrorCategory;
use Closure;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 5 §42 & Production Observability Middleware.
 *
 * Tracks request execution duration, database query metrics (count, writes,
 * total DB time, slowest query, transactions and the longest one, whether a
 * connection was opened at all), the response size, and safe structured
 * context (request_id, route, method, status, user_id, attempt_id).
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
        $writeCount = 0;
        $totalDbTime = 0.0;
        $slowestQueryTime = 0.0;
        $transactionCount = 0;
        $transactionDepth = 0;
        $transactionStartedAt = null;
        $longestTransactionMs = 0.0;

        DB::listen(function ($query) use (&$queryCount, &$writeCount, &$totalDbTime, &$slowestQueryTime) {
            $queryCount++;
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writeCount++;
            }
            $time = (float) $query->time; // in milliseconds in Laravel
            $totalDbTime += $time;
            if ($time > $slowestQueryTime) {
                $slowestQueryTime = $time;
            }
        });

        // Outermost transactions only: how many, and the longest one (the
        // time row locks taken inside it are held).
        Event::listen(TransactionBeginning::class, function () use (&$transactionCount, &$transactionDepth, &$transactionStartedAt) {
            if ($transactionDepth++ === 0) {
                $transactionCount++;
                $transactionStartedAt = microtime(true);
            }
        });
        $endTransaction = function () use (&$transactionDepth, &$transactionStartedAt, &$longestTransactionMs) {
            if ($transactionDepth > 0 && --$transactionDepth === 0 && $transactionStartedAt !== null) {
                $longestTransactionMs = max($longestTransactionMs, (microtime(true) - $transactionStartedAt) * 1000);
                $transactionStartedAt = null;
            }
        };
        Event::listen(TransactionCommitted::class, $endTransaction);
        Event::listen(TransactionRolledBack::class, $endTransaction);

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
                'db_writes' => $writeCount,
                'db_duration_ms' => round($totalDbTime, 2),
                'slowest_query_ms' => round($slowestQueryTime, 2),
                'db_transactions' => $transactionCount,
                'db_longest_transaction_ms' => round($longestTransactionMs, 2),
                // Whether this request opened a MySQL connection at all. On the
                // shared host the limit is new connections per second, so
                // counting these per second is the number that matters.
                'db_connected' => $this->openedDatabaseConnection(),
                'response_bytes' => isset($response) ? $this->responseBytes($response) : null,
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

    private function openedDatabaseConnection(): bool
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->getRawPdo() instanceof \PDO || $connection->getRawReadPdo() instanceof \PDO) {
                return true;
            }
        }

        return false;
    }

    private function responseBytes(Response $response): ?int
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            || $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return null;
        }

        $content = $response->getContent();

        return $content === false ? null : strlen($content);
    }
}
