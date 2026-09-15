<?php

namespace App\Actions\Exam;

use App\Enums\QuestionType;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;

/**
 * Pure calculation of an attempt's grade based on the frozen snapshot and the
 * student's recorded answers (MCQ auto-graded + Essay manually awarded).
 *
 * @return array{total_points: int, earned_points: int, percentage: int, passed: bool, requires_manual_grading: bool}
 */
class CalculateExamResultAction
{
    public function execute(ExamAttempt $attempt): array
    {
        $attempt->loadMissing([
            'attemptQuestions.attemptOptions',
            'answers',
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
                if ($answer->option_id === null) {
                    continue;
                }

                /** @var ExamAttemptOption|null $correctOption */
                $correctOption = $attemptQuestion->attemptOptions
                    ->firstWhere('is_correct', true);

                if ($correctOption && $answer->option_id === $correctOption->option_id) {
                    $earnedPoints += $attemptQuestion->points;
                }
            }
        }

        $percentage = $totalPoints > 0
            ? (int) round($earnedPoints / $totalPoints * 100)
            : 0;

        return [
            'total_points' => $totalPoints,
            'earned_points' => $earnedPoints,
            'percentage' => $percentage,
            'passed' => $attempt->pass_percentage <= $percentage,
            'requires_manual_grading' => $hasUngradedEssay,
        ];
    }
}
