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
     *  - Generates a new cryptographically random temporary password
     *  - Updates the hashed password in the database
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

        $temporaryPassword = $this->credentialsService->generateTemporaryPassword();
        $student->password = Hash::make($temporaryPassword);
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
