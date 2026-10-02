<?php

namespace App\Actions\Student;

use App\Actions\Audit\RecordAuditLogAction;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Irreversibly remove one student account. Attempt/answer deletion is possible
 * only when the caller explicitly confirms it; database FK restrictions provide
 * a second guard against accidental history loss.
 */
class ForceDeleteStudentAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(User $actor, User $student, bool $deleteAcademicHistory): void
    {
        DB::transaction(function () use ($actor, $student, $deleteAcademicHistory): void {
            $student = User::query()->lockForUpdate()->findOrFail($student->getKey());
            abort_unless($student->isStudent(), 404, 'Student account not found.');

            // Close access before enumerating history. The transaction and the
            // RESTRICT FK below make a racing attempt start fail safely rather
            // than disappear through a user-row cascade.
            $student->is_active = false;
            $student->save();
            $student->tokens()->delete();

            $attempts = ExamAttempt::withTrashed()
                ->where('student_id', $student->getKey())
                ->lockForUpdate()
                ->get();

            if ($attempts->isNotEmpty() && ! $deleteAcademicHistory) {
                throw ValidationException::withMessages([
                    'delete_academic_history' => [
                        'This student has exam-attempt history. Confirm its permanent deletion or anonymize the account instead.',
                    ],
                ]);
            }

            foreach ($attempts as $attempt) {
                $attempt->forceFill(['deleted_by' => $actor->getKey()])->save();
                $audit = $this->audit->execute(
                    'exam.attempt.force_delete',
                    $attempt,
                    [
                        'attempt_id' => $attempt->getKey(),
                        'exam_id' => $attempt->exam_id,
                        'student_id' => $student->getKey(),
                        'reason' => 'student_force_delete',
                    ],
                    $actor,
                );
                if (! $audit) {
                    throw new \RuntimeException('The attempt-deletion audit record could not be saved.');
                }
                $attempt->forceDelete();
            }

            $audit = $this->audit->execute(
                'student.force_delete',
                $student,
                [
                    'student_id' => $student->getKey(),
                    'attempts_deleted' => $attempts->count(),
                    'academic_history_explicitly_deleted' => $deleteAcademicHistory,
                ],
                $actor,
            );
            if (! $audit) {
                throw new \RuntimeException('The student-deletion audit record could not be saved.');
            }

            // Other account-owned rows are removed only inside this separately
            // authorized, explicitly confirmed force-delete operation. The
            // attempts above are removed first; their FK is RESTRICT, not CASCADE.
            $student->delete();
        });
    }
}
