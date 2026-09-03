<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use Illuminate\Support\Facades\DB;

/**
 * Grades an attempt by persisting the result computed against the frozen
 * snapshot, populating each answer's `is_correct` and `points_earned`, then
 * marking the attempt as submitted and stamping `submitted_at`.
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
            'answers',
            'exam',
        ]);

        $result = $this->calculateResult->execute($attempt);

        DB::transaction(function () use ($attempt, $result) {
            $answersByQuestion = $attempt->answers->keyBy('question_id');

            foreach ($attempt->attemptQuestions as $attemptQuestion) {
                /** @var ExamAnswer|null $answer */
                $answer = $answersByQuestion->get($attemptQuestion->question_id);

                if (! $answer) {
                    continue;
                }

                /** @var ExamAttemptOption|null $correctOption */
                $correctOption = $attemptQuestion->attemptOptions
                    ->firstWhere('is_correct', true);

                $isCorrect = $correctOption && $answer->option_id === $correctOption->option_id;

                $answer->is_correct = $isCorrect;
                $answer->points_earned = $isCorrect ? $attemptQuestion->points : 0;
                $answer->save();
            }

            $now = now();

            $attempt->score = $result['earned_points'];
            $attempt->percentage = $result['percentage'];
            $attempt->status = ExamAttemptStatus::Submitted->value;
            $attempt->submitted_at = $now;

            $attempt->save();
        });

        return $attempt->fresh();
    }
}
