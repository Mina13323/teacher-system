<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use Illuminate\Support\Facades\DB;

/**
 * Auto-grades MCQ questions upon submission and sets status to submitted or grading.
 * Scores are draft until published by staff.
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
        ]);

        $result = $this->calculateResult->execute($attempt);

        DB::transaction(function () use ($attempt, $result) {
            $answersByQuestion = $attempt->answers->keyBy('question_id');

            foreach ($attempt->attemptQuestions as $attemptQuestion) {
                if ($attemptQuestion->question_type === QuestionType::Essay->value) {
                    continue;
                }

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
                    $attempt->student->notify(new \App\Notifications\ResultAvailableNotification($attempt));
                }
            }

            $attempt->save();
        });

        return $attempt->fresh();
    }
}
