<?php

namespace App\Actions\Student;

use App\Actions\Audit\RecordAuditLogAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Remove direct account identifiers while retaining the student's row, role,
 * enrollments, access periods, and academic history for institutional records.
 */
class AnonymizeStudentAction
{
    public function __construct(
        private readonly RecordAuditLogAction $audit,
        private readonly SetAccountActiveStateAction $setActiveState,
    ) {
    }

    public function execute(User $actor, User $student): User
    {
        return DB::transaction(function () use ($actor, $student): User {
            $student = User::query()->lockForUpdate()->findOrFail($student->getKey());
            abort_unless($student->isStudent(), 404, 'Student account not found.');

            // Deactivate through the shared transition action so the latest
            // access-period state and server tokens remain consistent.
            $student = $this->setActiveState->execute($student, false);

            // Remove channels that can identify or contact the account.
            // Academic/enrollment records remain untouched.
            $student->pushSubscriptions()->delete();
            $student->notifications()->delete();

            $student->forceFill([
                'name' => 'Anonymized student '.$student->getKey(),
                'email' => 'anonymized-student-'.$student->getKey().'@example.invalid',
                'student_code' => null,
                'phone' => null,
                'avatar' => null,
                'profile_completed_at' => null,
                'email_verified_at' => null,
                'remember_token' => null,
                'password' => Hash::make(bin2hex(random_bytes(48))),
                'is_active' => false,
                'can_access_lessons' => false,
                'can_take_exams' => false,
                'can_join_competitions' => false,
                'must_change_password' => true,
                'bio' => null,
                'notification_preferences' => null,
            ])->save();

            $audit = $this->audit->execute(
                'student.anonymize',
                $student,
                [
                    'student_id' => $student->getKey(),
                    'account_deactivated' => true,
                    'academic_history_retained' => true,
                ],
                $actor,
            );
            if (! $audit) {
                throw new \RuntimeException('The anonymization audit record could not be saved.');
            }

            return $student->fresh(['roles', 'latestAccessPeriod']);
        });
    }
}
