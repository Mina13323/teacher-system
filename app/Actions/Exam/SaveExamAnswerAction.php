<?php

namespace App\Actions\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAnswer;
use App\Models\ExamAnswerOption;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Records (or updates) a student's answer for a question within an active attempt.
 * Supports MCQ options (single- and multi-select) and Essay answer text.
 *
 * Multi-select: the full selected option set is persisted in the normalized
 * `exam_answer_options` child rows (authoritative for grading). The legacy
 * `exam_answers.option_id` column is kept in sync for single-select answers so
 * historical readers of that column keep working; for multi-select answers it
 * mirrors the single selection when exactly one option is chosen and stays null
 * otherwise.
 *
 * Idempotent: re-saving the same selection replaces the child set atomically —
 * never duplicates rows (unique index (answer_id, option_id) backs this up).
 */
class SaveExamAnswerAction
{
    /**
     * @param  list<int>|null  $optionIds  Selected option ids (multi-select aware).
     *                                     Null keeps the legacy single `optionId` path.
     */
    public function execute(
        ExamAttempt $attempt,
        int $questionId,
        ?int $optionId = null,
        ?string $answerText = null,
        ?array $optionIds = null,
        ?string $explanation = null,
        bool $explanationProvided = false
    ): ExamAttempt {
        if (! $attempt->status->isInProgress()) {
            throw new InvalidAttemptStateException('This attempt is already completed.');
        }

        if ($attempt->isExpired()) {
            $this->expireAttempt($attempt);
            throw new InvalidAttemptStateException('This attempt has expired.');
        }

        // Normalize the selection set: explicit set wins, otherwise the legacy
        // single option id becomes a set of one (or empty).
        $selection = $optionIds !== null
            ? array_values(array_unique(array_map('intval', $optionIds)))
            : ($optionId !== null ? [(int) $optionId] : []);

        DB::transaction(function () use ($attempt, $questionId, $selection, $answerText, $optionIds, $explanation, $explanationProvided) {
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

                return;
            }

            // Choice question: every selected id must belong to the frozen
            // snapshot of THIS question, and the set size must match the type.
            if ($selection === []) {
                if ($optionIds !== null) {
                    // Explicit empty set from a multi-select aware client:
                    // clear the selection (question returns to "unanswered").
                    ExamAnswer::query()
                        ->where('attempt_id', $locked->getKey())
                        ->where('question_id', $questionId)
                        ->delete(); // cascades exam_answer_options rows

                    return;
                }

                // Legacy clients must always send a selection.
                throw new InvalidAttemptStateException('An option must be selected for multiple-choice questions.');
            }

            $snapshotOptionIds = ExamAttemptOption::query()
                ->where('attempt_question_id', $attemptQuestion->getKey())
                ->pluck('option_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($selection as $selectedId) {
                if (! in_array($selectedId, $snapshotOptionIds, true)) {
                    throw new InvalidAttemptStateException('This option does not belong to the chosen question.');
                }
            }

            $isMulti = $attemptQuestion->question_type === QuestionType::MultipleChoice->value;

            if (! $isMulti && count($selection) > 1) {
                throw new InvalidAttemptStateException('Only one option can be selected for a single-choice question.');
            }

            /** @var ExamAnswer $answer */
            $answerValues = [
                // Mirror the single selection for legacy readers; null for
                // a true multi-select set.
                'option_id' => count($selection) === 1 ? $selection[0] : null,
                'answer_text' => null,
                'answered_at' => now(),
            ];

            if ($attemptQuestion->explanation_enabled && $explanationProvided) {
                $answerValues['explanation'] = $explanation;
            } elseif (! $attemptQuestion->explanation_enabled) {
                $answerValues['explanation'] = null;
            }

            $answer = ExamAnswer::updateOrCreate(
                [
                    'attempt_id' => $locked->getKey(),
                    'question_id' => $questionId,
                ],
                $answerValues
            );

            // Replace the selection set atomically (idempotent re-saves).
            $answer->selectedOptions()->delete();
            foreach ($selection as $selectedId) {
                ExamAnswerOption::create([
                    'answer_id' => $answer->getKey(),
                    'option_id' => $selectedId,
                ]);
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
