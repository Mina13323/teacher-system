<?php

namespace App\Actions\Exam;

use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Exceptions\ExamStructureLockedException;
use App\Models\Exam;
use App\Models\Option;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a whole question paper in one request.
 *
 * A template can hand a teacher fifty blank questions. Authoring them one modal
 * at a time is not a real option, so the bulk endpoint accepts the entire paper
 * and upserts it. Entries carrying an `id` update the existing question; entries
 * without one are created. That makes the authoring screen safely re-submittable
 * — the teacher can save, spot a typo, and save again without duplicating rows.
 *
 * Only questions that already belong to this exam may be referenced, so one
 * teacher cannot rewrite another exam's paper through a guessed id.
 */
class SyncExamQuestionsAction
{
    /**
     * @param  array<int, array<string, mixed>>  $questions  validated question payloads
     * @return Collection<int, Question>
     *
     * @throws ExamStructureLockedException
     */
    public function execute(Exam $exam, array $questions): Collection
    {
        if ($exam->status === ExamStatus::Published) {
            throw new ExamStructureLockedException(
                'The questions of a published exam cannot be edited. Archive it first.'
            );
        }

        $ownedIds = $exam->questions()->pluck('id')->all();

        return DB::transaction(function () use ($exam, $questions, $ownedIds) {
            $saved = collect();

            foreach ($questions as $position => $payload) {
                $id = $payload['id'] ?? null;

                if ($id !== null && ! in_array((int) $id, $ownedIds, true)) {
                    // Reject rather than silently create: a foreign id is either
                    // a stale client or a probe, and neither should succeed.
                    throw ValidationException::withMessages([
                        'questions.'.$position.'.id' => 'That question does not belong to this exam.',
                    ]);
                }

                $type = QuestionType::from($payload['type']);

                $question = $id !== null
                    ? Question::query()->findOrFail((int) $id)
                    : new Question(['exam_id' => $exam->getKey()]);

                $question->fill([
                    'exam_id' => $exam->getKey(),
                    'question_text' => $payload['question_text'],
                    'type' => $type->value,
                    'points' => (int) $payload['points'],
                    // The paper's order is the order the teacher sees on screen.
                    'position' => $position + 1,
                    'reference_answer' => $payload['reference_answer'] ?? null,
                ]);
                $question->save();

                $this->syncOptions($question, $type, $payload['options'] ?? []);

                $saved->push($question->fresh(['options']));
            }

            return $saved;
        });
    }

    /**
     * Bring a question's options in line with the submitted list.
     *
     * An essay question has no options, so any that exist are removed — a
     * teacher who switches a question from multiple-choice to essay should not
     * leave a stale answer key attached to it.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(Question $question, QuestionType $type, array $options): void
    {
        if ($type->isEssay()) {
            $question->options()->delete();

            return;
        }

        $ownedOptionIds = $question->options()->pluck('id')->all();
        $seen = [];

        foreach ($options as $position => $option) {
            $optionId = $option['id'] ?? null;

            if ($optionId !== null && in_array((int) $optionId, $ownedOptionIds, true)) {
                $model = $question->options()->findOrFail((int) $optionId);
                $seen[] = (int) $optionId;
            } else {
                $model = new Option(['question_id' => $question->getKey()]);
            }

            $model->fill([
                'question_id' => $question->getKey(),
                'option_text' => $option['option_text'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'position' => $position + 1,
            ]);
            $model->save();

            if ($model->wasRecentlyCreated) {
                $seen[] = (int) $model->getKey();
            }
        }

        // Anything the teacher removed from the screen is deleted, so a
        // five-option question trimmed to four does not keep a ghost row.
        $question->options()
            ->whereNotIn('id', $seen === [] ? [0] : $seen)
            ->delete();
    }
}
