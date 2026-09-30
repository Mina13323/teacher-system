<?php

namespace App\Actions\Exam;

use App\Enums\QuestionType;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;

/**
 * Pure calculation of an attempt's grade based on the frozen snapshot and the
 * student's recorded answers (choice questions auto-graded + Essay manually
 * awarded).
 *
 * Choice grading semantics (exact, deterministic):
 *   single_choice   correct iff the selected option is the snapshot's single
 *                   correct option;
 *   multiple_choice correct iff the selected option SET exactly equals the
 *                   snapshot's correct option SET (all-or-nothing — no partial
 *                   credit policy is invented here; partial credit, if wanted,
 *                   is a separate explicit feature).
 *   legacy rows     (answer children absent) grade from `exam_answers.option_id`
 *                   exactly as before this feature existed, so historical
 *                   results are bit-for-bit reproducible.
 *
 * Precision rule: pass/fail is decided on the RAW percentage
 * (earned / total * 100, full float precision). The rounded integer is a
 * display value only and never alters the academic outcome.
 *
 * @return array{
 *     total_points: int,
 *     earned_points: int,
 *     raw_percentage: float,
 *     percentage: int,
 *     passed: bool,
 *     requires_manual_grading: bool
 * }
 */
class CalculateExamResultAction
{
    public function execute(ExamAttempt $attempt): array
    {
        $attempt->loadMissing([
            'attemptQuestions.attemptOptions',
            'answers.selectedOptions',
        ]);

        $totalPoints = 0;
        $earnedPoints = 0;
        $hasUngradedEssay = false;

        $answersByQuestion = $attempt->answers
            ->keyBy('question_id');

        foreach ($attempt->attemptQuestions as $attemptQuestion) {
            $totalPoints += $attemptQuestion->points;

            /** @var ExamAnswer|null $answer */
            $answer = $answersByQuestion->get($attemptQuestion->question_id);

            if (! $answer) {
                if ($attemptQuestion->question_type === QuestionType::Essay->value) {
                    $hasUngradedEssay = true;
                }
                continue;
            }

            if ($attemptQuestion->question_type === QuestionType::Essay->value) {
                if ($answer->points_earned !== null) {
                    $earnedPoints += (int) $answer->points_earned;
                } else {
                    $hasUngradedEssay = true;
                }
            } else {
                if ($answer->points_earned !== null) {
                    $earnedPoints += (int) $answer->points_earned;
                } else {
                    // Ungraded choice answer (e.g. calculation requested before
                    // auto-grading ran): score it here from the snapshot.
                    if ($this->isChoiceAnswerCorrect($attemptQuestion, $answer)) {
                        $earnedPoints += $attemptQuestion->points;
                    }
                }
            }
        }

        $rawPercentage = $totalPoints > 0
            ? ($earnedPoints / $totalPoints * 100)
            : 0.0;

        return [
            'total_points' => $totalPoints,
            'earned_points' => $earnedPoints,
            'raw_percentage' => $rawPercentage,
            'percentage' => (int) round($rawPercentage),
            // Pass/fail on RAW precision: 59.5 never passes a 60 threshold
            // just because the display value rounds up.
            'passed' => $rawPercentage >= $attempt->pass_percentage,
            'requires_manual_grading' => $hasUngradedEssay,
        ];
    }

    /**
     * Deterministic correctness of a choice answer against the frozen snapshot.
     */
    public function isChoiceAnswerCorrect(ExamAttemptQuestion $attemptQuestion, ExamAnswer $answer): bool
    {
        $correctOptions = $attemptQuestion->attemptOptions
            ->filter(fn (ExamAttemptOption $option) => (bool) $option->is_correct);

        if ($correctOptions->isEmpty()) {
            return false;
        }

        $selectedIds = $answer->selectedOptionIds();

        if ($selectedIds === []) {
            return false;
        }

        $isMulti = $attemptQuestion->question_type === QuestionType::MultipleChoice->value;

        if ($isMulti) {
            // Exact set match — all correct options and nothing else.
            $correctIds = $correctOptions
                ->map(fn (ExamAttemptOption $option) => (int) $option->option_id)
                ->sort()
                ->values()
                ->all();

            return $selectedIds === $correctIds;
        }

        // single_choice (and legacy snapshots with a null type): credit iff the
        // selection equals the first correct option — identical to the
        // pre-multi-select `firstWhere('is_correct', true)` rule, so historical
        // grading semantics are preserved exactly. Publish validation now
        // guarantees exactly one correct option for new single-choice questions.
        $firstCorrectId = (int) $correctOptions->first()->option_id;

        return $selectedIds === [$firstCorrectId];
    }
}
