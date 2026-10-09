<?php

namespace App\Services\LoadTest;

/**
 * Policy for the shared fixture password (LOADTEST_PASSWORD).
 *
 * There is deliberately no default: the fixture accounts are real, active
 * logins on a public staging site (one of them a real-role teacher), so the
 * secret must be supplied explicitly in the staging environment. The value is
 * never echoed; failures describe the rule that was broken, not the input.
 */
class LoadTestPassword
{
    public const MIN_LENGTH = 16;

    /** Previously documented default; it is public and must never be accepted again. */
    private const RETIRED_DEFAULT = 'LoadTest#Staging-2026';

    /**
     * @throws LoadTestSafetyException when the password is missing or weak
     */
    public static function assertValid(mixed $password): void
    {
        $problems = self::problems($password);

        if ($problems !== []) {
            throw new LoadTestSafetyException(
                'Refusing to run: LOADTEST_PASSWORD is missing or too weak for staging fixture accounts.'
                ."\n - ".implode("\n - ", $problems)
                ."\n Set a strong secret in the STAGING environment only (never commit it)."
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function problems(mixed $password): array
    {
        if (! is_string($password) || trim($password) === '') {
            return ['LOADTEST_PASSWORD is not set.'];
        }

        $problems = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $problems[] = 'It must be at least '.self::MIN_LENGTH.' characters long.';
        }
        if (strcasecmp($password, self::RETIRED_DEFAULT) === 0) {
            $problems[] = 'It must not be the previously published default password.';
        }
        if (preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
            || preg_match('/[^a-zA-Z\d]/', $password) !== 1) {
            $problems[] = 'It must mix lower-case, upper-case, digits and symbols.';
        }
        if (preg_match('/^(.)\1+$/u', $password) === 1) {
            $problems[] = 'It must not be a single repeated character.';
        }

        return $problems;
    }
}
