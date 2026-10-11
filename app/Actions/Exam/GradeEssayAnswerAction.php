<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a teacher's grade for one essay answer and recomputes the attempt.
 *
 * Only handed-in attempts (submitted, grading, published) are graded. A
 * running attempt is refused (422): the student may still change the text. An
 * attempt past its deadline that the sweep has not reached yet is finalized
 * first by FinalizeExpiredAttemptAction, exactly as the sweep would; a strictly
 * expired attempt is never graded. The state is checked, and the score and
 * status recomputed, on the attempt row locked inside the grading transaction.
 */
class GradeEssayAnswerAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
        private readonly RecordAuditLogAction $auditLog,
        private readonly FinalizeExpiredAttemptAction $finalizeExpired,
    ) {
    }

    public function execute(
        User $staffUser,
        ExamAttempt $attempt,
        int $questionId,
        int $awardedPoints,
        ?string $feedback = null
    ): ExamAttempt {
        $current = ExamAttempt::query()->findOrFail($attempt->getKey());
        if ($current->status->isInProgress() && $current->isExpired()) {
            // Overdue: the lifecycle step the sweep owns (it re-checks under its own lock).
            $this->finalizeExpired->execute($current);
        }

        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_id', $questionId)
            ->first();

        if (! $attemptQuestion) {
            throw new InvalidAttemptStateException('Question does not belong to this attempt.');
        }

        // The frozen snapshot is authoritative here, not the live Question
        // model: only a snapshot essay question may enter the manual grading
        // path. This stops staff (or a replayed request) from overwriting an
        // auto-graded MCQ's correctness and awarded points.
        //
        // A NULL question_type is deliberately rejected rather than treated as
        // an essay. Legacy snapshot rows written before migration
        // 2026_01_01_000801 have NULL here, but those attempts can only contain
        // MCQs: the Essay case was added to App\Enums\QuestionType in the very
        // same commit that added both that column and this grading action, so
        // no pre-existing attempt can ever be awaiting manual essay grading.
        // Do not add a lenient fallback to the live Question here.
        if ($attemptQuestion->question_type !== QuestionType::Essay->value) {
            throw new InvalidAttemptStateException('Only essay questions can be graded manually.');
        }

        // Out-of-range awards are reported against the `awarded_points` field:
        // Teacher\AttemptController::gradeEssay catches InvalidArgumentException
        // and converts it into a field-level 422. Keep this exception type.
        if ($awardedPoints < 0 || $awardedPoints > $attemptQuestion->points) {
            throw new \InvalidArgumentException("Awarded points must be between 0 and {$attemptQuestion->points}.");
        }

        $graded = DB::transaction(function () use ($attempt, $attemptQuestion, $staffUser, $questionId, $awardedPoints, $feedback) {
            // Lock the attempt so two concurrent grading requests cannot both
            // recompute the total from a stale set of answers, and decide from
            // this locked row rather than the route-bound model.
            $locked = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());

            if (! in_array($locked->status->value, ExamAttemptStatus::submittedValues(), true)) {
                throw new InvalidAttemptStateException($locked->status->isInProgress()
                    ? 'This attempt is still in progress. Essays can be graded only after the student submits or the time is up.'
                    : 'This attempt expired without being submitted, so it is not graded.');
            }

            $answer = ExamAnswer::firstOrCreate(
                [
                    'attempt_id' => $locked->getKey(),
                    'question_id' => $questionId,
                ],
                [
                    'answered_at' => now(),
                ]
            );

            $answer->points_earned = $awardedPoints;
            $answer->is_correct = $awardedPoints === $attemptQuestion->points;
            $answer->feedback = $feedback;
            $answer->graded_by = $staffUser->getKey();
            $answer->graded_at = now();
            $answer->save();

            // Recompute from the freshly persisted answers.
            $result = $this->calculateResult->execute($locked);

            $locked->score = $result['earned_points'];
            $locked->percentage = $result['percentage'];
            // Keep the full-precision pass/fail input in lockstep with the
            // display percentage (outcome() reads raw_percentage first).
            $locked->raw_percentage = $result['raw_percentage'];
            $locked->graded_by = $staffUser->getKey();

            if ($locked->status->isSubmitted() && $result['requires_manual_grading']) {
                $locked->status = ExamAttemptStatus::Grading->value;
            } elseif ($locked->status === ExamAttemptStatus::Grading && ! $result['requires_manual_grading']) {
                $locked->status = $locked->grades_published_at !== null
                    ? ExamAttemptStatus::Published->value
                    : ExamAttemptStatus::Submitted->value;
            }

            $locked->save();

            return $locked;
        });

        $this->auditLog->execute('grade.essay', $graded, [
            'question_id' => $questionId,
            'awarded_points' => $awardedPoints,
            'max_points' => $attemptQuestion->points,
            'has_feedback' => $feedback !== null ? '1' : '0',
            'graded_by' => $staffUser->getKey(),
        ], $staffUser);

        return $graded->fresh(['answers.selectedOptions', 'attemptQuestions']);
    }
}
