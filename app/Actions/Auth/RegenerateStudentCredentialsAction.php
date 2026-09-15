<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\StudentCredentialService;
use Illuminate\Support\Facades\Hash;

class RegenerateStudentCredentialsAction
{
    public function __construct(
        private readonly StudentCredentialService $credentialsService,
    ) {
    }

    /**
     * Regenerates credentials for an existing student:
     *  - Generates a student_code if not yet present
     *  - Generates a random temporary password (ELM@ + 5 random digits)
     *  - Updates the hashed password in the database
     *  - Sets must_change_password = true
     *  - Revokes all existing sessions/tokens
     *  - Returns the credentials for one-time reveal to staff
     *
     * @return array{student: User, credentials: array{student_code: string, login: string, temporary_password: string}}
     */
    public function execute(User $student): array
    {
        if (empty($student->student_code)) {
            $student->student_code = $this->credentialsService->generateStudentCode();
        }

        // Same canonical generator as creation — a reset must not fall back to
        // a scheme derived from the student code.
        $temporaryPassword = $this->credentialsService->generateTemporaryPassword();
        $student->password = Hash::make($temporaryPassword);
        $student->must_change_password = true;
        $student->save();

        // Invalidate all existing tokens on credential regeneration
        $student->tokens()->delete();

        return [
            'student' => $student,
            'credentials' => [
                'student_code' => $student->student_code,
                'login' => $student->email,
                'temporary_password' => $temporaryPassword,
            ],
        ];
    }
}
