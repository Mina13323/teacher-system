<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamMakeUpAssignmentStatus;
use App\Exceptions\AttemptLimitReachedException;
use App\Exceptions\ExamNotAccessibleException;
use App\Exceptions\ExamNotPublishedException;
use App\Exceptions\ExamWindowClosedException;
use App\Exceptions\ExamWindowNotOpenException;
use App\Actions\Integrity\CreateAttemptIntegritySettingsAction;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamMakeUpAssignment;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly FinalizeExpiredAttemptAction $finalizeExpired,
    ) {
    }

    public function execute(User $student, Exam $exam, bool $rulesAcknowledged): ExamAttempt
    {
        if (! $rulesAcknowledged) {
            throw ValidationException::withMessages([
                'rules_acknowledged' => ['You must acknowledge the exam rules before starting.'],
            ]);
        }

        if (! $exam->status->isPublished()) {
            throw new ExamNotPublishedException();
        }

        if (! $this->enrollments->isEnrolled($student, $exam->course_id)) {
            throw new ExamNotAccessibleException();
        }

        if ($student->isStudent() && (! $student->canTakeExams() || ! $student->hasActiveAccess())) {
            throw new ExamNotAccessibleException('You do not have access to exams.');
        }

        try {
            return DB::transaction(function () use ($student, $exam, $rulesAcknowledged) {
                $studentId = $student->getKey();
                $examId = $exam->getKey();

                // Expire any stale in-progress attempt whose server-side
                // deadline has passed so it no longer blocks a new attempt.
                $this->expireStaleAttempts($studentId, $examId);

                // Server-authoritative window enforcement. Only windowed exams
                // are affected; legacy exams (starts_at/ends_at both null) keep
                // their duration-per-attempt behaviour.
                $this->assertWindowAllowsStart($exam);

                // Re-check for a currently active attempt.
                $existing = ExamAttempt::query()
                    ->where('student_id', $studentId)
                    ->where('exam_id', $examId)
                    ->where('status', ExamAttemptStatus::InProgress->value)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    // Acknowledging on resume may backfill legacy active attempts,
                    // but never changes start time, deadline, or attempt number.
                    if ($rulesAcknowledged && $existing->rules_acknowledged_at === null) {
                        $existing->rules_acknowledged_at = now();
                        $existing->save();
                    }

                    return $existing;
                }

                $completedCount = ExamAttempt::query()
                    ->where('student_id', $studentId)
                    ->where('exam_id', $examId)
                    ->whereIn('status', [
                        ExamAttemptStatus::Submitted->value,
                        ExamAttemptStatus::Grading->value,
                        ExamAttemptStatus::Published->value,
                        ExamAttemptStatus::Expired->value,
                    ])
                    ->count();

                $makeUpAssignment = null;
                if ($completedCount >= $exam->max_attempts) {
                    $makeUpAssignment = ExamMakeUpAssignment::query()
                        ->where('exam_id', $examId)
                        ->where('student_id', $studentId)
                        ->where('status', ExamMakeUpAssignmentStatus::Assigned->value)
                        ->where('active_key', $examId.':'.$studentId)
                        ->lockForUpdate()
                        ->first();

                    if (! $makeUpAssignment) {
                        throw new AttemptLimitReachedException();
                    }
                }

                // Soft-deleted attempts no longer count against the student's
                // configured limit, but their numbers remain reserved forever.
                $nextAttemptNumber = (int) ExamAttempt::withTrashed()
                    ->where('student_id', $studentId)
                    ->where('exam_id', $examId)
                    ->max('attempt_number') + 1;

                $startedAt = now();

                // CORE INVARIANT:
                // Student deadline = MIN(attempt_started_at + duration, exam_window_end)
                // The exam window end is a hard deadline that active attempts cannot exceed.
                $expiresAt = $exam->calculateAttemptExpiry($startedAt);

                $attempt = ExamAttempt::create([
                    'exam_id' => $examId,
                    'student_id' => $studentId,
                    'attempt_number' => $nextAttemptNumber,
                    'started_at' => $startedAt,
                    'expires_at' => $expiresAt,
                    'last_heartbeat_at' => $startedAt,
                    'status' => ExamAttemptStatus::InProgress->value,
                    'pass_percentage' => $exam->pass_percentage,
                    'rules_acknowledged_at' => $rulesAcknowledged ? now() : null,
                    'active_key' => $studentId.':'.$examId,
                ]);

                $this->buildSnapshot->execute($attempt, $exam);

                // Freeze the exam's integrity configuration onto this attempt so
                // later teacher changes never alter the rules of this attempt.
                $this->createIntegritySettings->execute($attempt, $exam);

                if ($makeUpAssignment) {
                    $makeUpAssignment->status = ExamMakeUpAssignmentStatus::Used;
                    $makeUpAssignment->active_key = null;
                    $makeUpAssignment->attempt_id = $attempt->getKey();
                    $makeUpAssignment->used_at = now();
                    $makeUpAssignment->save();
                }

                // Keep the freshly-created model instance so the controller can
                // accurately distinguish a new attempt from a resumed one via
                // wasRecentlyCreated. The persisted start/deadline fields are
                // already authoritative on this instance.
                return $attempt;
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

    /**
     * Enforce the official exam window against server time.
     *
     *  - If starts_at is configured: now < starts_at -> ExamWindowNotOpenException
     *  - If ends_at is configured: now >= ends_at    -> ExamWindowClosedException
     *
     * No value from the request is consulted, so a manipulated browser clock
     * cannot open or extend the window.
     */
    private function assertWindowAllowsStart(Exam $exam): void
    {
        $now = now();

        if ($exam->starts_at !== null && $now->lessThan($exam->starts_at)) {
            throw new ExamWindowNotOpenException();
        }

        if ($exam->ends_at !== null && $now->greaterThanOrEqualTo($exam->ends_at)) {
            throw new ExamWindowClosedException();
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
                // Finalize per the exam's expiry policy (auto-submit grades the
                // saved answers; 'expire' keeps the legacy blank expiry). The
                // old behavior here marked the attempt expired WITHOUT grading,
                // silently discarding every saved answer.
                $this->finalizeExpired->execute($attempt);
            });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505'
            || (int) $driverCode === 1062
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'unique constraint');
    }
}
