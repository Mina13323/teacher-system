<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\User;
use InvalidArgumentException;

class GradeEssayAnswerAction
{
    public function __construct(
        private readonly CalculateExamResultAction $calculateResult,
    ) {
    }

    public function execute(
        User $staffUser,
        ExamAttempt $attempt,
        int $questionId,
        int $awardedPoints,
        ?string $feedback = null
    ): ExamAttempt {
        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_id', $questionId)
            ->first();

        if (! $attemptQuestion) {
            throw new InvalidArgumentException('Question does not belong to this attempt.');
        }

        if ($awardedPoints < 0 || $awardedPoints > $attemptQuestion->points) {
            throw new InvalidArgumentException("Awarded points must be between 0 and {$attemptQuestion->points}.");
        }

        $answer = ExamAnswer::firstOrCreate(
            [
                'attempt_id' => $attempt->getKey(),
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

        $result = $this->calculateResult->execute($attempt);

        $attempt->score = $result['earned_points'];
        $attempt->percentage = $result['percentage'];
        $attempt->graded_by = $staffUser->getKey();

        if ($attempt->status->isSubmitted() && $result['requires_manual_grading']) {
            $attempt->status = ExamAttemptStatus::Grading->value;
        }

        $attempt->save();

        return $attempt->fresh(['answers', 'attemptQuestions']);
    }
}
