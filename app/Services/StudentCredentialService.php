<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class StudentCredentialService
{
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
            // Also take into account the count of existing students if any
            $count = User::query()->whereNotNull('student_code')->count();
            if ($count > 0) {
                $nextNumber = 1000 + $count + 1;
            }
        }

        // Guarantee uniqueness loop in case of race
        while (User::query()->where('student_code', 'ELM-'.$nextNumber)->exists()) {
            $nextNumber++;
        }

        return 'ELM-'.$nextNumber;
    }

    /**
     * Generates a unique system email based on student name and student code.
     */
    public function generateEmail(string $name, string $studentCode): string
    {
        $numericPart = preg_replace('/\D/', '', $studentCode) ?: Str::random(4);
        $slug = Str::slug($name, '');

        if ($slug === '' || ! preg_match('/^[a-z0-9]+$/', $slug)) {
            $base = 'elm'.$numericPart;
        } else {
            $base = substr($slug, 0, 12).'.elm'.$numericPart;
        }

        $email = $base.'@elmasry.local';

        $attempts = 0;
        while (User::query()->where('email', $email)->exists() && $attempts < 50) {
            $attempts++;
            $email = $base.'.'.Str::lower(Str::random(3)).'@elmasry.local';
        }

        return $email;
    }

    /**
     * Generates a cryptographically secure, random temporary password.
     */
    public function generateTemporaryPassword(): string
    {
        // 10 characters: 'Elm#' prefix + 4 random characters + 3 random digits
        return 'Elm#'.Str::random(4).random_int(100, 999);
    }

    /**
     * Generates a complete set of credentials for a new student.
     *
     * @return array{student_code: string, email: string, temporary_password: string}
     */
    public function generateCredentials(string $name): array
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
