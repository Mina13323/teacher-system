<?php

namespace Tests\Feature\LoadTest;

use App\Services\LoadTest\LoadTestLoginAllowance;
use Tests\Feature\ApiTestCase;

/**
 * The staging-only login allowance for load-test fixture students must never
 * alter the normal login throttle for anyone else, or on any other environment.
 */
class LoadTestLoginThrottleTest extends ApiTestCase
{
    private const IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.rate_limit.auth' => 2,            // normal per-IP / per-account budget
            'loadtest.login_per_minute_per_ip' => 5, // fixture-only per-IP budget
        ]);
    }

    private function asStaging(): void
    {
        config([
            'app.env' => 'staging',
            'app.url' => 'https://staging.maherelmasry.com',
            'loadtest.required_database' => (string) config('database.connections.'.config('database.default').'.database'),
        ]);
    }

    private function fixtureEmail(int $n): string
    {
        return sprintf('loadtest.student.%04d@staging.maherelmasry.com', $n);
    }

    /** Wrong-password login (401 when allowed through, 429 when throttled). */
    private function attempt(string $email): int
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'wrong-password'])
            ->getStatusCode();
    }

    /** @return list<int> statuses for $count distinct identifiers from one IP. */
    private function burst(callable $emailFor, int $count): array
    {
        $statuses = [];
        for ($i = 1; $i <= $count; $i++) {
            $statuses[] = $this->attempt($emailFor($i));
        }

        return $statuses;
    }

    public function test_production_keeps_the_existing_throttle_even_for_fixture_emails(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://maherelmasry.com']);

        $this->assertSame([401, 401, 429, 429], $this->burst(fn ($i) => $this->fixtureEmail($i), 4));
    }

    public function test_normal_staging_users_keep_the_existing_throttle(): void
    {
        $this->asStaging();

        $this->assertSame(
            [401, 401, 429, 429],
            $this->burst(fn ($i) => "student{$i}@example.test", 4)
        );
    }

    public function test_fixture_students_get_the_staging_allowance_without_consuming_the_normal_bucket(): void
    {
        $this->asStaging();

        // 5 fixture logins from one IP pass (allowance), the 6th hits the larger cap.
        $this->assertSame([401, 401, 401, 401, 401, 429], $this->burst(fn ($i) => $this->fixtureEmail($i), 6));
    }

    public function test_fixture_logins_do_not_use_up_the_normal_ip_budget_of_other_users(): void
    {
        $this->asStaging();

        $this->burst(fn ($i) => $this->fixtureEmail($i), 4);

        // The same IP still has its full normal budget for ordinary accounts.
        $this->assertSame(401, $this->attempt('someone@example.test'));
        $this->assertSame(401, $this->attempt('someone.else@example.test'));
        $this->assertSame(429, $this->attempt('third@example.test'));
    }

    public function test_per_account_limit_still_applies_to_fixture_students(): void
    {
        $this->asStaging();

        $this->assertSame(
            [401, 401, 429],
            [$this->attempt($this->fixtureEmail(1)), $this->attempt($this->fixtureEmail(1)), $this->attempt($this->fixtureEmail(1))]
        );
    }

    public function test_allowance_cannot_be_activated_outside_full_staging_identity(): void
    {
        $cases = [
            'production env, staging url' => ['app.env' => 'production'],
            'staging env, production url' => ['app.url' => 'https://maherelmasry.com'],
            'staging env, www production url' => ['app.url' => 'https://www.maherelmasry.com'],
            'staging env, lookalike url' => ['app.url' => 'https://staging.maherelmasry.com.evil.example'],
            'staging env + url, wrong database' => ['loadtest.required_database' => 'u481922752_staging'],
            'local env' => ['app.env' => 'local'],
        ];

        foreach ($cases as $label => $override) {
            $this->asStaging();
            config($override);

            $this->assertFalse(
                app(LoadTestLoginAllowance::class)->applies($this->fixtureEmail(1)),
                $label
            );
        }

        // And end to end on "production": the 3rd fixture login from one IP is throttled.
        $this->asStaging();
        config(['app.env' => 'production']);
        $this->assertSame(429, $this->burst(fn ($i) => $this->fixtureEmail($i), 3)[2]);
    }

    public function test_allowance_applies_only_with_full_staging_identity_and_exact_email(): void
    {
        $this->asStaging();
        $allowance = app(LoadTestLoginAllowance::class);

        $this->assertTrue($allowance->applies($this->fixtureEmail(1)));
        $this->assertTrue($allowance->applies($this->fixtureEmail(1500)));
        $this->assertTrue($allowance->applies('  LoadTest.Student.0007@Staging.MaherElMasry.com '), 'login lower-cases and trims');
    }

    public function test_malformed_and_lookalike_identifiers_never_get_the_allowance(): void
    {
        $this->asStaging();
        $allowance = app(LoadTestLoginAllowance::class);

        foreach ([
            '',
            'loadtest.student.1@staging.maherelmasry.com',
            'loadtest.student.00011@staging.maherelmasry.com',
            'loadtest.student.abcd@staging.maherelmasry.com',
            'loadtest.student.0001@maherelmasry.com',
            'loadtest.student.0001@staging.maherelmasry.com.evil.example',
            'loadtest.student.0001@evil-staging.maherelmasry.com',
            'loadtest.student.0001@staging.maherelmasry.co',
            'xloadtest.student.0001@staging.maherelmasry.com',
            'loadtest.student.0001@staging.maherelmasry.com,other@example.test',
            "loadtest.student.0001@staging.maherelmasry.com\nother",
            'loadtest.teacher@staging.maherelmasry.com',
            'loadtestXstudent.0001@staging.maherelmasry.com',
            'LT-0001', // student_code login
            'someone@example.test',
        ] as $identifier) {
            $this->assertFalse($allowance->applies($identifier), var_export($identifier, true));
        }
    }

    public function test_lookalike_emails_use_the_normal_throttle_end_to_end(): void
    {
        $this->asStaging();

        $this->assertSame(
            [401, 401, 429],
            $this->burst(fn ($i) => "loadtest.student.{$i}@staging.maherelmasry.com.evil.example", 3)
        );
    }

    public function test_there_is_no_generic_switch_to_disable_throttling(): void
    {
        $this->asStaging();
        config(['loadtest.disable_throttle' => true, 'api.rate_limit.disable' => true]);

        $this->assertSame([401, 401, 429], $this->burst(fn ($i) => "normal{$i}@example.test", 3));
    }
}
