<?php

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * MySQL connector that retries ONLY a refused connection attempt.
 *
 * Why this is safe: the retry wraps PDO connection creation, which runs before
 * the request sends its first statement. A refused connect has executed
 * nothing, so trying to connect again cannot repeat a write, an answer save, a
 * submission or a grading run. Queries on an open connection are never
 * retried here.
 *
 * Limits: at most `connect_retries` extra attempts (at most 3), exponential
 * backoff with ±50% jitter (about 50–150 ms, then 150–450 ms, then
 * 450–1350 ms), and a total wait budget (`connect_retry_budget_ms`).
 *
 * The host's refusal ([2002] Operation not permitted) is a limit on new
 * connections per second, so retries inside the same second meet the same
 * limit. `connect_retry_window_ms` makes the LAST retry wait until at least
 * that long after the first refusal, so it lands in a later second. Each retry is
 * logged as DB_CONNECT_TRANSIENT, and a refusal that outlasts the retries is
 * logged as DB_CONNECT_FINAL and rethrown unchanged, so the request still
 * fails explicitly (rendered as a 503).
 */
class RetryingMySqlConnector extends MySqlConnector
{
    public function createConnection($dsn, array $config, array $options)
    {
        $maxRetries = max(0, min(3, (int) ($config['connect_retries'] ?? 0)));
        $baseMs = max(1, (int) ($config['connect_retry_base_ms'] ?? 100));
        $budgetMs = max(0, (int) ($config['connect_retry_budget_ms'] ?? 1000));
        $windowMs = max(0, (int) ($config['connect_retry_window_ms'] ?? 0));

        $waitedMs = 0;
        for ($retry = 0; ; $retry++) {
            try {
                return $this->withoutLeftoverTransaction(parent::createConnection($dsn, $config, $options), $options);
            } catch (Throwable $e) {
                if (! ConnectionRefusal::matches($e)) {
                    throw $e;
                }

                $delayMs = $this->backoffMs($retry, $baseMs);
                if ($windowMs > 0 && $retry === $maxRetries - 1) {
                    $delayMs = max($delayMs, $windowMs - $waitedMs);
                }

                if ($retry >= $maxRetries || $waitedMs + $delayMs > $budgetMs) {
                    Log::error('db.connect.failed', [
                        'error_category' => 'DB_CONNECT_FINAL',
                        'mysql_errno' => ConnectionRefusal::errorNumber($e),
                        'attempts' => $retry + 1,
                        'waited_ms' => $waitedMs,
                    ]);

                    throw $e;
                }

                Log::warning('db.connect.retry', [
                    'error_category' => 'DB_CONNECT_TRANSIENT',
                    'mysql_errno' => ConnectionRefusal::errorNumber($e),
                    'attempt' => $retry + 1,
                    'delay_ms' => $delayMs,
                ]);

                $this->pause($delayMs);
                $waitedMs += $delayMs;
            }
        }
    }

    /**
     * A persistent connection (DB_PERSISTENT) is reused by the next request
     * on the same PHP worker. PHP normally rolls back an open transaction
     * when a request ends, but if one is still open here, it belongs to a
     * request that died mid-way: roll it back (releasing its row locks) so
     * this request never runs inside, or commits, someone else's work.
     * pdo_mysql reads this from the server status flags, without a query.
     */
    protected function withoutLeftoverTransaction(PDO $pdo, array $options): PDO
    {
        if (! empty($options[PDO::ATTR_PERSISTENT]) && $pdo->inTransaction()) {
            $pdo->rollBack();
            Log::warning('db.connect.leftover_transaction', ['error_category' => 'DB_PERSISTENT_ROLLBACK']);
        }

        return $pdo;
    }

    /** base × 3^retry, ±50% jitter. */
    protected function backoffMs(int $retry, int $baseMs): int
    {
        $nominal = $baseMs * (3 ** $retry);

        return (int) round($nominal * (0.5 + mt_rand() / mt_getrandmax()));
    }

    protected function pause(int $ms): void
    {
        usleep($ms * 1000);
    }
}
