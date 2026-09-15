<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Records (or updates) a student's answer for a question within an active attempt.
 * Supports both MCQ options and Essay answer text.
 */
class SaveExamAnswerAction
{
    public function execute(
        ExamAttempt $attempt,
        int $questionId,
        ?int $optionId = null,
        ?string $answerText = null
    ): ExamAttempt {
        if (! $attempt->status->isInProgress()) {
            throw new InvalidAttemptStateException('This attempt is already completed.');
        }

        if ($attempt->isExpired()) {
            $this->expireAttempt($attempt);
            throw new InvalidAttemptStateException('This attempt has expired.');
        }

        DB::transaction(function () use ($attempt, $questionId, $optionId, $answerText) {
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if (! $locked->status->isInProgress()) {
                throw new InvalidAttemptStateException('This attempt is already completed.');
            }

            if ($locked->isExpired()) {
                throw new InvalidAttemptStateException('This attempt has expired.');
            }

            /** @var ExamAttemptQuestion|null $attemptQuestion */
            $attemptQuestion = ExamAttemptQuestion::query()
                ->where('attempt_id', $locked->getKey())
                ->where('question_id', $questionId)
                ->first();

            if (! $attemptQuestion) {
                throw new InvalidAttemptStateException('This question is not part of the attempt.');
            }

            if ($attemptQuestion->question_type === QuestionType::Essay->value) {
                ExamAnswer::updateOrCreate(
                    [
                        'attempt_id' => $locked->getKey(),
                        'question_id' => $questionId,
                    ],
                    [
                        'option_id' => null,
                        'answer_text' => $answerText,
                        'answered_at' => now(),
                    ]
                );
            } else {
                if (! $optionId) {
                    throw new InvalidAttemptStateException('An option must be selected for multiple-choice questions.');
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
                        'attempt_id' => $locked->getKey(),
                        'question_id' => $questionId,
                    ],
                    [
                        'option_id' => $optionId,
                        'answer_text' => null,
                        'answered_at' => now(),
                    ]
                );
            }
        });

        return $attempt->fresh();
    }

    private function expireAttempt(ExamAttempt $attempt): void
    {
        $attempt->status = ExamAttemptStatus::Expired->value;
        $attempt->active_key = null;
        $attempt->save();
    }
}
