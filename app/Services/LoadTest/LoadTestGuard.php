<?php

namespace App\Services\LoadTest;

use Illuminate\Support\Facades\DB;

/**
 * Hard staging-only guard for the load-test fixture commands.
 *
 * Never relies on APP_ENV alone: the environment, the APP_URL host and the
 * actual connected database must ALL match the configured staging values.
 */
class LoadTestGuard
{
    /**
     * @throws LoadTestSafetyException when any safety condition fails
     */
    public function assertStaging(): void
    {
        $failures = $this->failures();

        if ($failures !== []) {
            throw new LoadTestSafetyException(
                "Refusing to run: this command is STAGING-ONLY.\n - ".implode("\n - ", $failures)
            );
        }
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        $failures = [];

        $requiredEnv = (string) config('loadtest.required_env');
        if (config('app.env') !== $requiredEnv) {
            $failures[] = 'APP_ENV is "'.config('app.env').'", expected "'.$requiredEnv.'".';
        }

        $requiredHost = (string) config('loadtest.required_host');
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($requiredHost === '' || $host !== strtolower($requiredHost)) {
            $failures[] = 'APP_URL host is "'.$host.'", expected "'.$requiredHost.'".';
        }

        $requiredDb = (string) config('loadtest.required_database');
        $connection = config('database.default');
        $configured = (string) config("database.connections.{$connection}.database");
        $actual = $configured;
        try {
            $actual = (string) (DB::connection()->getDatabaseName() ?: $configured);
        } catch (\Throwable) {
            // Fall through to the configured name; the mismatch check below still applies.
        }
        if ($requiredDb === '' || $configured !== $requiredDb || $actual !== $requiredDb) {
            $failures[] = 'Database is "'.$actual.'", expected "'.$requiredDb.'".';
        }

        return $failures;
    }
}
