<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Exceptions\AttemptLimitReachedException;
use App\Exceptions\ExamNotAccessibleException;
use App\Exceptions\ExamNotPublishedException;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Support\Facades\DB;

/**
 * Starts (or resumes) a student's attempt on an exam.
 *
 * Rules:
 *  - the exam must be published,
 *  - the student must be actively enrolled in the exam's course,
 *  - the student must not have exhausted their attempt limit,
 *  - an existing in-progress attempt is returned instead of creating a new one
 *    (preventing duplicate active attempts from double-click / retries),
 *  - a snapshot is frozen at start so later teacher edits do not affect it.
 */
class StartExamAttemptAction
{
    public function __construct(
        private readonly BuildAttemptSnapshotAction $buildSnapshot,
        private readonly EnrollmentService $enrollments,
    ) {
    }

    public function execute(User $student, Exam $exam): ExamAttempt
    {
        if (! $exam->status->isPublished()) {
            throw new ExamNotPublishedException();
        }

        $courseId = $exam->course_id;

        if (! $this->enrollments->isEnrolled($student, $courseId)) {
            throw new ExamNotAccessibleException();
        }

        return DB::transaction(function () use ($student, $exam, $courseId) {
            // Serialize concurrent starts for the same student + exam.
            $existingActive = ExamAttempt::query()
                ->where('student_id', $student->getKey())
                ->where('exam_id', $exam->getKey())
                ->where('status', ExamAttemptStatus::InProgress->value)
                ->lockForUpdate()
                ->first();

            if ($existingActive) {
                return $existingActive;
            }

            $attemptCount = ExamAttempt::query()
                ->where('student_id', $student->getKey())
                ->where('exam_id', $exam->getKey())
                ->whereIn('status', [ExamAttemptStatus::Submitted->value, ExamAttemptStatus::Expired->value])
                ->count();

            if ($attemptCount >= $exam->max_attempts) {
                throw new AttemptLimitReachedException();
            }

            $startedAt = now();
            $attempt = ExamAttempt::create([
                'exam_id' => $exam->getKey(),
                'student_id' => $student->getKey(),
                'attempt_number' => $attemptCount + 1,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addMinutes($exam->duration_minutes),
                'status' => ExamAttemptStatus::InProgress->value,
            ]);

            $this->buildSnapshot->execute($attempt, $exam);

            return $attempt->fresh();
        });
    }
}
