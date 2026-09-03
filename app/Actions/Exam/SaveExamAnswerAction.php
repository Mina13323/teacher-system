<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Records (or updates) a student's answer for a question within an active
 * attempt.
 *
 * Validation is entirely against the attempt's frozen snapshot, never the live
 * questions/options tables, so a student can keep answering a question whose
 * live record was edited or deleted by a teacher mid-attempt:
 *  - the attempt is owned by the student and still in progress,
 *  - the attempt has not expired server-side,
 *  - the question belongs to the attempt's frozen snapshot,
 *  - the option belongs to that question in the frozen snapshot.
 *
 * Concurrency: the attempt row is locked (`FOR UPDATE`) and the answer is
 * stored with a unique (attempt_id, question_id) index, so concurrent answer
 * writes to the same attempt are serialized and duplicate entries are rejected.
 */
class SaveExamAnswerAction
{
    public function execute(ExamAttempt $attempt, int $questionId, int $optionId): ExamAttempt
    {
        if (! $attempt->status->isInProgress()) {
            throw new InvalidAttemptStateException('This attempt is already completed.');
        }

        if ($attempt->isExpired()) {
            $this->expireAttempt($attempt);
            throw new InvalidAttemptStateException('This attempt has expired.');
        }

        DB::transaction(function () use ($attempt, $questionId, $optionId) {
            // Lock the attempt row so concurrent submits/answers serialize and
            // we always operate on the freshest status.
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if (! $locked->status->isInProgress()) {
                throw new InvalidAttemptStateException('This attempt is already completed.');
            }

            if ($locked->isExpired()) {
                throw new InvalidAttemptStateException('This attempt has expired.');
            }

            $attemptQuestion = ExamAttemptQuestion::query()
                ->where('attempt_id', $locked->getKey())
                ->where('question_id', $questionId)
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
                    'attempt_id' => $locked->getKey(),
                    'question_id' => $questionId,
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
        $attempt->active_key = null;
        $attempt->save();
    }
}
