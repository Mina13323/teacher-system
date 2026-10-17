<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\DB;

/**
 * Auto-grades choice questions upon submission and sets status to submitted or grading.
 * Scores are draft until published by staff.
 *
 * Grading is exact and deterministic against the frozen snapshot:
 *   single_choice   one correct option (legacy-compatible rule);
 *   multiple_choice exact-set match (all-or-nothing);
 *   essay           manual grading path, untouched here.
 *
 * Idempotent: re-grading an already graded attempt recomputes the same values
 * (submit paths lock and re-check status before reaching this action).
 */
class GradeExamAttemptAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
    ) {
    }

    /**
     * @param  bool  $relationsLoadedUnderLock  The caller locked the attempt row and
     *                                          loaded `attemptQuestions.attemptOptions` and
     *                                          `answers.selectedOptions` after taking the
     *                                          lock, so they are current and need no reload.
     */
    public function execute(ExamAttempt $attempt, bool $relationsLoadedUnderLock = false): ExamAttempt
    {
        $relations = ['attemptQuestions.attemptOptions', 'answers.selectedOptions'];

        if ($relationsLoadedUnderLock) {
            $attempt->loadMissing($relations);
        } else {
            $attempt->load($relations);
        }

        $run = function () use ($attempt) {
            $answersByQuestion = $attempt->answers->keyBy('question_id');
            $now = now();
            $rows = [];

            foreach ($attempt->attemptQuestions as $attemptQuestion) {
                if ($attemptQuestion->question_type === QuestionType::Essay->value) {
                    continue;
                }

                /** @var ExamAnswer|null $answer */
                $answer = $answersByQuestion->get($attemptQuestion->question_id);

                if (! $answer) {
                    // Unanswered question: record an explicit zero-grade row so
                    // the breakdown is complete (same as the previous behavior).
                    $rows[] = [
                        'attempt_id' => $attempt->getKey(),
                        'question_id' => $attemptQuestion->question_id,
                        'is_correct' => false,
                        'points_earned' => 0,
                    ];

                    continue;
                }

                $isCorrect = $this->calculateResult->isChoiceAnswerCorrect($attemptQuestion, $answer);
                $points = $isCorrect ? (int) $attemptQuestion->points : 0;

                $rows[] = [
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $attemptQuestion->question_id,
                    'is_correct' => $isCorrect,
                    'points_earned' => $points,
                ];

                // Keep the loaded answer in step with the row written below,
                // so the result is calculated from exactly the persisted grades.
                $answer->is_correct = $isCorrect;
                $answer->points_earned = $points;
                $answer->syncOriginalAttributes(['is_correct', 'points_earned']);
            }

            // One statement for every choice grade: answered rows are updated
            // and unanswered questions get their zero row, on the unique
            // (attempt_id, question_id) key. Essay rows are never touched.
            if ($rows !== []) {
                ExamAnswer::query()->upsert(
                    $rows,
                    ['attempt_id', 'question_id'],
                    ['is_correct', 'points_earned']
                );
            }

            // Unanswered choice questions earn 0 whether or not their zero row
            // is in memory, so the calculation matches the persisted rows.
            $result = $this->calculateResult->execute($attempt);

            $attempt->score = $result['earned_points'];
            $attempt->percentage = $result['percentage'];
            // Full-precision value used for pass/fail (see CalculateExamResultAction).
            $attempt->raw_percentage = $result['raw_percentage'];
            $attempt->status = $result['requires_manual_grading']
                ? ExamAttemptStatus::Grading->value
                : ExamAttemptStatus::Submitted->value;
            $attempt->active_key = null;
            if (! $attempt->submitted_at) {
                $attempt->submitted_at = $now;
            }

            if (! $result['requires_manual_grading'] && ($attempt->exam?->show_result_immediately ?? true)) {
                $attempt->grades_published_at = $now;
                // The student is read after commit, outside the locked
                // transaction; the notification itself was already after commit.
                DB::afterCommit(function () use ($attempt) {
                    $attempt->student?->notify(new ResultAvailableNotification($attempt));
                });
            }

            // Idempotency sentinel (P0.12): the FIRST grading run stamps
            // scored_at; repeated finalization must never rewrite it.
            $attempt->scored_at = $attempt->scored_at ?? now();
            $attempt->save();

            // Re-read so callers see the stored values (raw_percentage is a
            // DECIMAL(6,3) column, so the stored value is rounded). The exam
            // is the same row, so it is handed over instead of re-queried.
            $fresh = $attempt->fresh();
            if ($fresh !== null && $attempt->relationLoaded('exam')) {
                $fresh->setRelation('exam', $attempt->exam);
            }

            return $fresh;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }
}
