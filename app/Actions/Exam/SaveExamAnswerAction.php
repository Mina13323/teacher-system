<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

/**
 * Records (or updates) a student's answer for a question within an active
 * attempt, verifying that:
 *  - the attempt is owned by the student and still in progress,
 *  - the attempt has not expired server-side,
 *  - the question belongs to the attempt's frozen snapshot,
 *  - the option belongs to that question in the frozen snapshot.
 */
class SaveExamAnswerAction
{
    public function execute(ExamAttempt $attempt, Question $question, int $optionId): ExamAttempt
    {
        if (! $attempt->status->isInProgress()) {
            throw new InvalidAttemptStateException('This attempt is already completed.');
        }

        if ($attempt->isExpired()) {
            $this->expireAttempt($attempt);
            throw new InvalidAttemptStateException('This attempt has expired.');
        }

        DB::transaction(function () use ($attempt, $question, $optionId) {
            $attemptQuestion = ExamAttemptQuestion::query()
                ->where('attempt_id', $attempt->getKey())
                ->where('question_id', $question->getKey())
                ->first();

            if (! $attemptQuestion) {
                throw new InvalidAttemptStateException('This question is not part of the attempt.');
            }

            $optionInSnapshot = ExamAttemptOption::query()
                ->where('attempt_question_id', $attemptQuestion->getKey())
                ->where('option_id', $optionId)
                ->exists();

            if (! $optionInSnapshot) {
                throw new InvalidAttemptStateException('This option does not belong to the chosen question.');
            }

            ExamAnswer::updateOrCreate(
                [
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $question->getKey(),
                ],
                [
                    'option_id' => $optionId,
                    'answered_at' => now(),
                ]
            );
        });

        return $attempt->fresh();
    }

    private function expireAttempt(ExamAttempt $attempt): void
    {
        $attempt->status = ExamAttemptStatus::Expired->value;
        $attempt->save();
    }
}
