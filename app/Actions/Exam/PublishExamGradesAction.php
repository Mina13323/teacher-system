<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\DB;

/**
 * Publishes an attempt's grades to its student.
 *
 * Publication never ends a running exam: an attempt still in progress before
 * its deadline is refused (422) and left exactly as it was. An attempt whose
 * deadline has passed but which the per-minute sweep has not finalized yet is
 * finalized first by FinalizeExpiredAttemptAction, under the exam's own expiry
 * policy, exactly as the sweep would. An attempt that ended `expired` under the
 * strict policy is never graded, so it cannot be published either.
 */
class PublishExamGradesAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
        private readonly RecordAuditLogAction $auditLog,
        private readonly FinalizeExpiredAttemptAction $finalizeExpired,
        private readonly GradeExamAttemptAction $gradeAttempt,
    ) {
    }

    public function execute(User $staffUser, ExamAttempt $attempt): ExamAttempt
    {
        // Decide from the current row, not the route-bound model.
        $current = ExamAttempt::query()->findOrFail($attempt->getKey());

        if ($current->status->isInProgress()) {
            if (! $current->isExpired()) {
                throw new InvalidAttemptStateException(
                    'This attempt is still in progress. Grades can be published only after the student submits or the time is up.'
                );
            }

            // Overdue: the lifecycle step the sweep owns (it re-checks under its own lock).
            $this->finalizeExpired->execute($current);
        }

        $firstPublication = DB::transaction(function () use ($attempt, $staffUser) {
            // Lock the row so two concurrent publish requests cannot both
            // believe they are the first publication, and so the state checked
            // below is the state that is published.
            $locked = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());

            if (! in_array($locked->status->value, ExamAttemptStatus::submittedValues(), true)) {
                throw new InvalidAttemptStateException($locked->status->isInProgress()
                    ? 'This attempt is still in progress. Grades can be published only after the student submits or the time is up.'
                    : 'This attempt expired without being submitted, so it has no grades to publish.');
            }

            // Handed-in attempts that were never scored (legacy rows) are
            // scored now, inside this transaction.
            if ($locked->scored_at === null) {
                $locked = $this->gradeAttempt->execute($locked);
            }

            $locked->unsetRelation('answers');
            $locked->unsetRelation('attemptQuestions');
            $result = $this->calculateResult->execute($locked);

            $wasPublished = $locked->grades_published_at !== null;

            $locked->score = $result['earned_points'];
            $locked->percentage = $result['percentage'];
            // Keep the full-precision pass/fail input in lockstep with the
            // display percentage (outcome() reads raw_percentage first).
            $locked->raw_percentage = $result['raw_percentage'];
            $locked->status = ExamAttemptStatus::Published->value;
            // Preserve the original publication timestamp on re-publication so
            // repeated calls are idempotent and the audit trail is not rewritten.
            $locked->grades_published_at = $locked->grades_published_at ?? now();
            $locked->graded_by = $staffUser->getKey();
            $locked->save();

            $attempt->setRawAttributes($locked->getAttributes(), true);

            return ! $wasPublished;
        });

        $attempt->loadMissing('student');

        // Notify only on the first publication, and only once the transaction
        // has committed so a rollback cannot announce grades that do not exist.
        if ($firstPublication && $attempt->student) {
            $attempt->student->notify(new ResultAvailableNotification($attempt));
        }

        $this->auditLog->execute('grade.publish', $attempt, [
            'first_publication' => $firstPublication ? '1' : '0',
            'student_id' => $attempt->student_id,
            'exam_id' => $attempt->exam_id,
            'score' => $attempt->score,
            'percentage' => $attempt->percentage,
        ], $staffUser);

        return $attempt->fresh();
    }
}
