<?php

namespace Tests\Unit;

use App\Database\ConnectionRefusal;
use App\Database\RetryingMySqlConnector;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use Tests\TestCase;

class RetryingMySqlConnectorTest extends TestCase
{
    private const REFUSED = 'SQLSTATE[HY000] [2002] Operation not permitted';

    private function connector(array $outcomes): FakeConnector
    {
        return new FakeConnector($outcomes);
    }

    private function config(int $retries = 2, int $budget = 1000): array
    {
        return ['connect_retries' => $retries, 'connect_retry_base_ms' => 100, 'connect_retry_budget_ms' => $budget];
    }

    public function test_recognizes_only_connection_refusals(): void
    {
        $this->assertTrue(ConnectionRefusal::matches(new PDOException(self::REFUSED)));
        $this->assertTrue(ConnectionRefusal::matches(new PDOException('SQLSTATE[HY000] [1040] Too many connections')));
        $this->assertTrue(ConnectionRefusal::matches(new PDOException("SQLSTATE[42000] [1226] User 'u' has exceeded the 'max_user_connections' resource (current value: 50)")));
        $this->assertTrue(ConnectionRefusal::matches(new \RuntimeException('wrapped', 0, new PDOException(self::REFUSED))));

        $this->assertFalse(ConnectionRefusal::matches(new PDOException('SQLSTATE[HY000] [2002] No such file or directory')));
        $this->assertFalse(ConnectionRefusal::matches(new PDOException('SQLSTATE[HY000] [2002] Connection refused')));
        $this->assertFalse(ConnectionRefusal::matches(new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'u'")));
        $this->assertFalse(ConnectionRefusal::matches(new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')));
        $this->assertFalse(ConnectionRefusal::matches(new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found')));
        $this->assertFalse(ConnectionRefusal::matches(null));
    }

    public function test_succeeds_after_a_refused_connect(): void
    {
        Log::spy();
        $connector = $this->connector([new PDOException(self::REFUSED), 'ok']);

        $pdo = $connector->createConnection('mysql:host=x', $this->config(), []);

        $this->assertInstanceOf(PDO::class, $pdo);
        $this->assertSame(2, $connector->connects);
        $this->assertCount(1, $connector->pauses);
        $this->assertGreaterThanOrEqual(50, $connector->pauses[0]);
        $this->assertLessThanOrEqual(150, $connector->pauses[0]);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($m, $c) => $m === 'db.connect.retry' && $c['error_category'] === 'DB_CONNECT_TRANSIENT' && $c['mysql_errno'] === 2002);
    }

    public function test_gives_up_after_the_retry_limit_and_rethrows_the_original_error(): void
    {
        Log::spy();
        $refused = new PDOException(self::REFUSED);
        $connector = $this->connector([$refused, $refused, $refused, 'ok']);

        try {
            $connector->createConnection('mysql:host=x', $this->config(2), []);
            $this->fail('Expected the refusal to be rethrown.');
        } catch (PDOException $e) {
            $this->assertSame($refused, $e);
        }

        $this->assertSame(3, $connector->connects);
        $this->assertCount(2, $connector->pauses);
        $this->assertGreaterThanOrEqual(150, $connector->pauses[1]);
        $this->assertLessThanOrEqual(450, $connector->pauses[1]);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($m, $c) => $m === 'db.connect.failed' && $c['error_category'] === 'DB_CONNECT_FINAL' && $c['attempts'] === 3);
    }

    public function test_never_retries_other_connection_errors(): void
    {
        // (Laravel itself already retries a few "lost connection" errors such as
        // 1045 once, immediately; this connector adds nothing for those.)
        $denied = new PDOException("SQLSTATE[HY000] [1049] Unknown database 'x'");
        $connector = $this->connector([$denied, 'ok']);

        $this->expectExceptionObject($denied);
        try {
            $connector->createConnection('mysql:host=x', $this->config(), []);
        } finally {
            $this->assertSame(1, $connector->connects);
            $this->assertSame([], $connector->pauses);
        }
    }

    public function test_zero_retries_disables_the_retry(): void
    {
        Log::spy();
        $connector = $this->connector([new PDOException(self::REFUSED), 'ok']);

        $this->expectException(PDOException::class);
        try {
            $connector->createConnection('mysql:host=x', $this->config(0), []);
        } finally {
            $this->assertSame(1, $connector->connects);
        }
    }

    public function test_respects_the_total_wait_budget(): void
    {
        Log::spy();
        $refused = new PDOException(self::REFUSED);
        $connector = $this->connector([$refused, $refused, $refused, 'ok']);

        $this->expectException(PDOException::class);
        try {
            // A 40 ms budget is smaller than the first backoff (≥ 50 ms).
            $connector->createConnection('mysql:host=x', $this->config(2, 40), []);
        } finally {
            $this->assertSame(1, $connector->connects);
            $this->assertSame([], $connector->pauses);
        }
    }

    public function test_caps_retries_at_three_whatever_the_config(): void
    {
        Log::spy();
        $refused = new PDOException(self::REFUSED);
        $connector = $this->connector(array_fill(0, 10, $refused));

        $this->expectException(PDOException::class);
        try {
            $connector->createConnection('mysql:host=x', $this->config(50, 100000), []);
        } finally {
            $this->assertSame(4, $connector->connects);
        }
    }

    public function test_the_mysql_driver_uses_the_retrying_connector(): void
    {
        $this->assertInstanceOf(RetryingMySqlConnector::class, $this->app->make('db.connector.mysql'));
    }
}

/** Replaces the real PDO connect and sleep so the retry logic can be observed. */
class FakeConnector extends RetryingMySqlConnector
{
    public int $connects = 0;

    /** @var list<int> */
    public array $pauses = [];

    public function __construct(private array $outcomes) {}

    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        $this->connects++;
        $outcome = array_shift($this->outcomes);
        if ($outcome instanceof \Throwable) {
            throw $outcome;
        }

        return new PDO('sqlite::memory:');
    }

    protected function pause(int $ms): void
    {
        $this->pauses[] = $ms;
    }
}
