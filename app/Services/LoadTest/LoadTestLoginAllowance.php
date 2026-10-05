<?php

namespace App\Services\LoadTest;

/**
 * Decides whether a login identifier may use the staging-only per-IP login
 * allowance (see the `login` limiter in AppServiceProvider).
 *
 * ALL of the following must hold; any failure means the normal throttle applies:
 *   - APP_ENV, the APP_URL host and the connected database are the staging ones
 *     (LoadTestGuard — the same guard the fixture commands use),
 *   - the identifier is exactly a fixture student email,
 *     loadtest.student.NNNN@staging.maherelmasry.com (4 digits, full-string match).
 *
 * The caller's IP is never used to decide eligibility, there is no environment
 * switch to turn this on, and nothing here touches the per-account limit.
 */
class LoadTestLoginAllowance
{
    public function __construct(private readonly LoadTestGuard $guard)
    {
    }

    public function applies(string $identifier): bool
    {
        $identifier = mb_strtolower(trim($identifier));

        if ($identifier === '' || ! $this->matchesFixtureStudentEmail($identifier)) {
            return false;
        }

        return $this->guard->failures() === [];
    }

    private function matchesFixtureStudentEmail(string $email): bool
    {
        $pattern = '/\A'
            .preg_quote((string) config('loadtest.student_email_prefix'), '/')
            .'\d{4}@'
            .preg_quote((string) config('loadtest.email_domain'), '/')
            .'\z/';

        return preg_match($pattern, $email) === 1;
    }
}
