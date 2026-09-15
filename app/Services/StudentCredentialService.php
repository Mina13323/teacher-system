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
     * Generates an initial temporary password using the business template:
     * {student_code}{academic_year}
     *
     * Example: ELM-10012026
     */
    public function generateTemporaryPassword(string $studentCode, ?string $academicYearSuffix = '2026'): string
    {
        $year = preg_match('/^\d{4}$/', (string) $academicYearSuffix) ? $academicYearSuffix : '2026';

        return $studentCode.$year;
    }

    /**
     * Generates a complete set of credentials for a new student.
     *
     * @return array{student_code: string, email: string, temporary_password: string}
     */
    public function generateCredentials(string $name, ?string $academicYear = '2026'): array
    {
        $code = $this->generateStudentCode();
        $email = $this->generateEmail($name, $code);
        $password = $this->generateTemporaryPassword($code, $academicYear);

        return [
            'student_code' => $code,
            'email' => $email,
            'temporary_password' => $password,
        ];
    }
}
