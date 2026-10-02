<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamMakeUpAssignmentStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\ExamMakeUpAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Create one active make-up grant for each specifically selected enrollee. */
class AssignExamMakeUpAction
{
    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    /**
     * @param  list<int>  $studentIds
     * @return list<ExamMakeUpAssignment>
     */
    public function execute(User $actor, Exam $exam, array $studentIds, ?string $reason = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $studentIds)));

        if ($exam->status !== ExamStatus::Published) {
            throw ValidationException::withMessages([
                'exam' => ['Make-up attempts can only be assigned for a published exam.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($actor, $exam, $ids, $reason): array {
                $enrolledIds = $exam->course
                    ->enrollments()
                    ->where('status', EnrollmentStatus::Active->value)
                    ->whereIn('student_id', $ids)
                    ->lockForUpdate()
                    ->pluck('student_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                sort($enrolledIds);
                $expectedIds = $ids;
                sort($expectedIds);

                if ($enrolledIds !== $expectedIds) {
                    throw ValidationException::withMessages([
                        'student_ids' => ['Every selected student must have an active enrollment in this exam course.'],
                    ]);
                }

                $activeKeys = array_map(fn (int $studentId) => $exam->getKey().':'.$studentId, $ids);
                $existing = ExamMakeUpAssignment::query()
                    ->whereIn('active_key', $activeKeys)
                    ->lockForUpdate()
                    ->exists();

                if ($existing) {
                    throw ValidationException::withMessages([
                        'student_ids' => ['At least one selected student already has an unused make-up assignment. No assignments were created.'],
                    ]);
                }

                $assignments = [];
                foreach ($ids as $studentId) {
                    $assignment = ExamMakeUpAssignment::create([
                        'exam_id' => $exam->getKey(),
                        'student_id' => $studentId,
                        'assigned_by' => $actor->getKey(),
                        'assigned_at' => now(),
                        'reason' => $reason,
                        'status' => ExamMakeUpAssignmentStatus::Assigned,
                        'active_key' => $exam->getKey().':'.$studentId,
                    ]);

                    $this->audit->execute('exam.makeup.assign', $assignment, [
                        'exam_id' => (string) $exam->getKey(),
                        'student_id' => (string) $studentId,
                        'reason' => (string) ($reason ?? ''),
                    ], $actor);

                    $assignments[] = $assignment;
                }

                return $assignments;
            });
        } catch (QueryException $exception) {
            // The active_key unique index is the final race-safe duplicate guard.
            if ($this->isUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'student_ids' => ['At least one selected student already has an unused make-up assignment. No assignments were created.'],
                ]);
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23505'
            || (int) $driverCode === 1062
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed')
            || str_contains($exception->getMessage(), 'unique constraint');
    }
}
