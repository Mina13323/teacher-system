<?php

namespace App\Actions\Exam;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Exceptions\GradeConflictException;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
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
 *
 * Changing the score of a result the student can already see notifies the
 * student once, after commit (as a regrade does); a grade that leaves the
 * score as it was sends nothing.
 *
 * Optimistic concurrency: a caller may pass the grade version it loaded
 * (ExamAnswer::gradeVersion). If the stored grade changed since, nothing is
 * written and a GradeConflictException carries the current grade; a conflict
 * is never resolved by last-write-wins. A save identical to the stored grade
 * writes nothing and records no audit entry.
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
        ?string $feedback = null,
        ?string $expectedVersion = null,
    ): ExamAttempt {
        $graded = $this->grade($staffUser, $attempt, $questionId, $awardedPoints, $feedback, $expectedVersion)['attempt'];

        return $graded->fresh(['answers.selectedOptions', 'attemptQuestions']);
    }

    /**
     * @return array{attempt: ExamAttempt, answer: ExamAnswer, result: array<string, mixed>, changed: bool}
     */
    public function grade(
        User $staffUser,
        ExamAttempt $attempt,
        int $questionId,
        int $awardedPoints,
        ?string $feedback = null,
        ?string $expectedVersion = null,
    ): array {
        $feedback = $feedback === '' ? null : $feedback;

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

        $outcome = DB::transaction(function () use ($attempt, $attemptQuestion, $staffUser, $questionId, $awardedPoints, $feedback, $expectedVersion) {
            // Lock the attempt so two concurrent grading requests cannot both
            // recompute the total from a stale set of answers, and decide from
            // this locked row rather than the route-bound model.
            $locked = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());

            if (! in_array($locked->status->value, ExamAttemptStatus::submittedValues(), true)) {
                throw new InvalidAttemptStateException($locked->status->isInProgress()
                    ? 'This attempt is still in progress. Essays can be graded only after the student submits or the time is up.'
                    : 'This attempt expired without being submitted, so it is not graded.');
            }

            $answer = ExamAnswer::query()
                ->where('attempt_id', $locked->getKey())
                ->where('question_id', $questionId)
                ->lockForUpdate()
                ->first();

            if ($expectedVersion !== null && ! hash_equals(ExamAnswer::gradeVersion($answer), $expectedVersion)) {
                throw new GradeConflictException(self::gradeState($answer));
            }

            $unchanged = $answer !== null
                && $answer->points_earned !== null
                && (int) $answer->points_earned === $awardedPoints
                && ($answer->feedback === '' ? null : $answer->feedback) === $feedback;

            if ($unchanged) {
                // A repeated, value-identical save: nothing to write or audit.
                return [$locked, $answer, $this->calculateResult->execute($locked), false, false];
            }

            $answer ??= ExamAnswer::create([
                'attempt_id' => $locked->getKey(),
                'question_id' => $questionId,
                'answered_at' => now(),
            ]);

            $answer->points_earned = $awardedPoints;
            $answer->is_correct = $awardedPoints === $attemptQuestion->points;
            $answer->feedback = $feedback;
            $answer->graded_by = $staffUser->getKey();
            $answer->graded_at = now();
            $answer->save();

            // Recompute from the freshly persisted answers.
            $result = $this->calculateResult->execute($locked);
            $previousScore = [$locked->score, $locked->percentage];

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

            $publishedScoreChanged = $locked->grades_published_at !== null
                && $previousScore !== [$locked->score, $locked->percentage];

            return [$locked, $answer, $result, true, $publishedScoreChanged];
        });

        [$graded, $answer, $result, $changed, $publishedScoreChanged] = $outcome;

        // After commit: a rolled-back grade can never announce a change.
        if ($publishedScoreChanged) {
            $graded->loadMissing('student');
            $graded->student?->notify(new ResultAvailableNotification($graded));
        }

        if ($changed) {
            $this->auditLog->execute('grade.essay', $graded, [
                'question_id' => $questionId,
                'awarded_points' => $awardedPoints,
                'max_points' => $attemptQuestion->points,
                'has_feedback' => $feedback !== null ? '1' : '0',
                'graded_by' => $staffUser->getKey(),
            ], $staffUser);
        }

        return ['attempt' => $graded, 'answer' => $answer, 'result' => $result, 'changed' => $changed];
    }

    /**
     * The grade as the grading screens show it.
     *
     * @return array{points_earned: int|null, feedback: string|null, graded_at: string|null, grade_version: string}
     */
    public static function gradeState(?ExamAnswer $answer): array
    {
        return [
            'points_earned' => $answer?->points_earned !== null ? (int) $answer->points_earned : null,
            'feedback' => $answer?->feedback === '' ? null : $answer?->feedback,
            'graded_at' => $answer?->graded_at?->toISOString(),
            'grade_version' => ExamAnswer::gradeVersion($answer),
        ];
    }
}
