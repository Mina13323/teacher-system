<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class StudentCredentialService
{
    /**
     * Temporary onboarding password format: PREFIX + random digits.
     *
     * Exposed as constants so callers and tests derive the expected shape from
     * one place instead of restating the literal.
     */
    public const TEMPORARY_PASSWORD_PREFIX = 'ELM@';

    public const TEMPORARY_PASSWORD_DIGITS = 5;

    /**
     * Regular expression matching a valid temporary password. Kept as a plain
     * literal so the shape is unambiguous; the generator derives its numeric
     * bounds from TEMPORARY_PASSWORD_DIGITS, and
     * TemporaryCredentialSecurityTest asserts the two stay in step.
     */
    public const TEMPORARY_PASSWORD_REGEX = '/^ELM@\d{5}$/';

    /**
     * Generates a unique, deterministic student code (e.g. ELM-1001, ELM-1002).
     */
    public function generateStudentCode(): string
    {
        $lastStudent = User::query()
            ->whereNotNull('student_code')
            ->where('student_code', 'like', 'ELM-%')
            ->orderByRaw('CAST(SUBSTRING(student_code, 5) AS UNSIGNED) DESC')
            ->first();

        $nextNumber = 1001;

        if ($lastStudent && preg_match('/^ELM-(\d+)$/', (string) $lastStudent->student_code, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        } else {
            $count = User::query()->whereNotNull('student_code')->count();
            if ($count > 0) {
                $nextNumber = 1000 + $count + 1;
            }
        }

        while (User::query()->where('student_code', 'ELM-'.$nextNumber)->exists()) {
            $nextNumber++;
        }

        return 'ELM-'.$nextNumber;
    }

    /**
     * Generates a unique system login email using the exact template:
     * {student_code}@student.com
     *
     * Example: ELM-1001@student.com
     */
    public function generateEmail(string $name, string $studentCode): string
    {
        $baseEmail = Str::lower($studentCode).'@student.com';
        $email = $baseEmail;

        $attempts = 0;
        while (User::query()->where('email', $email)->exists() && $attempts < 50) {
            $attempts++;
            $email = Str::lower($studentCode).$attempts.'@student.com';
        }

        return $email;
    }

    /**
     * Generates a random temporary onboarding password.
     *
     * Format: ELM@ + 5 random digits, e.g. ELM@48273.
     *
     * Deliberately simple and short: it is read off a printed card, typed on a
     * phone, and sent over WhatsApp, and the student is required to change it
     * on first login (must_change_password). It is an onboarding secret, not a
     * long-term one.
     *
     * The value is drawn from random_int(), a cryptographically secure
     * generator, and depends on NOTHING about the student: not the student
     * code, name, academic year, phone, email, date or id. That is the whole
     * point — the previous template ({student_code}{year}) was reconstructible
     * from information already printed on the credential card, which made the
     * password guessable by anyone who could read the card.
     *
     * No uniqueness constraint is applied: two students may coincidentally
     * share a password because each account stores its own hash. Collisions
     * are not a security property here, so they are neither prevented nor
     * retried.
     *
     * This method is the single source of truth for temporary passwords.
     * Student creation, staff credential reset and the WhatsApp message all
     * flow through the value produced here — none of them generates its own.
     */
    public function generateTemporaryPassword(): string
    {
        $min = 10 ** (self::TEMPORARY_PASSWORD_DIGITS - 1);
        $max = (10 ** self::TEMPORARY_PASSWORD_DIGITS) - 1;

        return self::TEMPORARY_PASSWORD_PREFIX.random_int($min, $max);
    }

    /**
     * Generates a complete set of credentials for a new student.
     *
     * The academic year still drives the student code/email conventions but no
     * longer influences the password, which is fully random.
     *
     * @return array{student_code: string, email: string, temporary_password: string}
     */
    public function generateCredentials(string $name, ?string $academicYear = '2026'): array
    {
        $code = $this->generateStudentCode();
        $email = $this->generateEmail($name, $code);
        $password = $this->generateTemporaryPassword();

        return [
            'student_code' => $code,
            'email' => $email,
            'temporary_password' => $password,
        ];
    }
}
