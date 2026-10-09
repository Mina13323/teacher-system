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
 * For capacity work each line also says where the request belongs, without
 * any extra query: `route_uri` (the route template, ids not filled in),
 * `area` (student / teacher / admin / auth / clock / shared), `traffic`
 * (exam / lms / auth / clock) and `role` when the user's roles were already
 * loaded by the request. Counting `db_connected` lines per second, grouped by
 * these, gives new MySQL connections per second and where they come from
 * (`php artisan metrics:requests`).
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
                'route_uri' => $request->route()?->uri(),
                'area' => self::area($request->path()),
                'traffic' => self::traffic($request->path()),
                'role' => $this->loadedRole($user),
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

    /** The API section a path belongs to. */
    public static function area(string $path): string
    {
        $section = explode('/', preg_replace('#^api/v\d+/#', '', trim($path, '/')))[0] ?? '';

        return match ($section) {
            'student', 'teacher', 'admin', 'auth' => $section,
            'time' => 'clock',
            default => 'shared',
        };
    }

    /**
     * Exam-taking requests (start, the attempt and everything under it)
     * versus everything else.
     */
    public static function traffic(string $path): string
    {
        $path = preg_replace('#^api/v\d+/#', '', trim($path, '/'));

        return match (true) {
            preg_match('#^student/attempts(/|$)#', $path) === 1,
            preg_match('#^student/exams/\d+/start$#', $path) === 1 => 'exam',
            $path === 'time' => 'clock',
            str_starts_with($path, 'auth/') => 'auth',
            default => 'lms',
        };
    }

    /** The user's first role, only when the request already loaded the roles. */
    private function loadedRole(mixed $user): ?string
    {
        if (! $user instanceof \Illuminate\Database\Eloquent\Model || ! $user->relationLoaded('roles')) {
            return null;
        }

        return $user->getRelation('roles')->pluck('name')->first();
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
