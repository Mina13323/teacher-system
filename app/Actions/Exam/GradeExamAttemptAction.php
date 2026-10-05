<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
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

    public function execute(ExamAttempt $attempt): ExamAttempt
    {
        $attempt->load([
            'attemptQuestions.attemptOptions',
            'answers.selectedOptions',
        ]);

        $run = function () use ($attempt) {
            $answersByQuestion = $attempt->answers->keyBy('question_id');

            foreach ($attempt->attemptQuestions as $attemptQuestion) {
                if ($attemptQuestion->question_type === QuestionType::Essay->value) {
                    continue;
                }

                /** @var ExamAnswer|null $answer */
                $answer = $answersByQuestion->get($attemptQuestion->question_id);

                if (! $answer) {
                    // Unanswered question: record an explicit zero-grade row so
                    // the breakdown is complete (same as the previous behavior).
                    ExamAnswer::create([
                        'attempt_id' => $attempt->getKey(),
                        'question_id' => $attemptQuestion->question_id,
                        'is_correct' => false,
                        'points_earned' => 0,
                    ]);
                    continue;
                }

                $isCorrect = $this->calculateResult->isChoiceAnswerCorrect($attemptQuestion, $answer);

                $answer->is_correct = $isCorrect;
                $answer->points_earned = $isCorrect ? $attemptQuestion->points : 0;
                $answer->save();
            }

            // Fresh calculation from the newly persisted answers
            $attempt->unsetRelation('answers');
            $attempt->load('answers.selectedOptions');
            $result = $this->calculateResult->execute($attempt);

            $now = now();

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
                if ($attempt->student) {
                    DB::afterCommit(function () use ($attempt) {
                        $attempt->student->notify(new \App\Notifications\ResultAvailableNotification($attempt));
                    });
                }
            }

            // Idempotency sentinel (P0.12): the FIRST grading run stamps
            // scored_at; repeated finalization must never rewrite it.
            $attempt->scored_at = $attempt->scored_at ?? now();
            $attempt->save();

            return $attempt->fresh();
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }
}
