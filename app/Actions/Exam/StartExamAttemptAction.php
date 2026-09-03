<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Exceptions\AttemptLimitReachedException;
use App\Exceptions\ExamNotAccessibleException;
use App\Exceptions\ExamNotPublishedException;
use App\Actions\Integrity\CreateAttemptIntegritySettingsAction;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Starts (or resumes) a student's attempt on an exam.
 *
 * Rules:
 *  - the exam must be published,
 *  - the student must be actively enrolled in the exam's course,
 *  - a stale in-progress attempt past its server-side deadline is transitioned
 *    to expired so it no longer blocks a fresh attempt,
 *  - an existing in-progress attempt (within its deadline) is returned instead
 *    of creating a new one (preventing duplicate active attempts from
 *    double-click / concurrent retries),
 *  - the attempt limit is enforced server-side against submitted + expired
 *    attempts,
 *  - the exam's `pass_percentage` and the question/option snapshot are frozen at
 *    attempt start so later teacher edits never affect this attempt.
 *
 * Concurrency: a unique index on `active_key` (set to `{student_id}:{exam_id}`
 * while in progress) guarantees at most one active attempt per student+exam at
 * the database level. If a concurrent request wins the race, its unique
 * violation is caught and the winning attempt is returned.
 */
class StartExamAttemptAction
{
    public function __construct(
        private readonly BuildAttemptSnapshotAction $buildSnapshot,
        private readonly CreateAttemptIntegritySettingsAction $createIntegritySettings,
        private readonly EnrollmentService $enrollments,
    ) {
    }

    public function execute(User $student, Exam $exam): ExamAttempt
    {
        if (! $exam->status->isPublished()) {
            throw new ExamNotPublishedException();
        }

        if (! $this->enrollments->isEnrolled($student, $exam->course_id)) {
            throw new ExamNotAccessibleException();
        }

        try {
            return DB::transaction(function () use ($student, $exam) {
                $studentId = $student->getKey();
                $examId = $exam->getKey();

                // Expire any stale in-progress attempt whose server-side
                // deadline has passed so it no longer blocks a new attempt.
                $this->expireStaleAttempts($studentId, $examId);

                // Re-check for a currently active attempt.
                $existing = ExamAttempt::query()
                    ->where('student_id', $studentId)
                    ->where('exam_id', $examId)
                    ->where('status', ExamAttemptStatus::InProgress->value)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $attemptCount = ExamAttempt::query()
                    ->where('student_id', $studentId)
                    ->where('exam_id', $examId)
                    ->whereIn('status', [
                        ExamAttemptStatus::Submitted->value,
                        ExamAttemptStatus::Expired->value,
                    ])
                    ->count();

                if ($attemptCount >= $exam->max_attempts) {
                    throw new AttemptLimitReachedException();
                }

                $startedAt = now();
                $attempt = ExamAttempt::create([
                    'exam_id' => $examId,
                    'student_id' => $studentId,
                    'attempt_number' => $attemptCount + 1,
                    'started_at' => $startedAt,
                    'expires_at' => $startedAt->copy()->addMinutes($exam->duration_minutes),
                    'status' => ExamAttemptStatus::InProgress->value,
                    'pass_percentage' => $exam->pass_percentage,
                    'active_key' => $studentId.':'.$examId,
                ]);

                $this->buildSnapshot->execute($attempt, $exam);

                // Freeze the exam's integrity configuration onto this attempt so
                // later teacher changes never alter the rules of this attempt.
                $this->createIntegritySettings->execute($attempt, $exam);

                return $attempt->fresh();
            });
        } catch (QueryException $e) {
            // The concurrent request created the in-progress attempt first; the
            // unique index on active_key (or the attempt_number unique) blocked
            // ours. Return the winner.
            if ($this->isUniqueViolation($e)) {
                $existing = ExamAttempt::query()
                    ->where('student_id', $student->getKey())
                    ->where('exam_id', $exam->getKey())
                    ->where('status', ExamAttemptStatus::InProgress->value)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function expireStaleAttempts(int $studentId, int $examId): void
    {
        ExamAttempt::query()
            ->where('student_id', $studentId)
            ->where('exam_id', $examId)
            ->where('status', ExamAttemptStatus::InProgress->value)
            ->where('expires_at', '<', now())
            ->each(function (ExamAttempt $attempt) {
                $attempt->status = ExamAttemptStatus::Expired->value;
                $attempt->active_key = null;
                $attempt->save();
            });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return in_array($sqlState, ['23000', '23505'], true)
            || (int) $driverCode === 1062
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'unique constraint');
    }
}
