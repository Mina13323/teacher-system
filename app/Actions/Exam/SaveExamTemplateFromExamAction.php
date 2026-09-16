<?php

namespace App\Actions\Exam;

use App\Models\Exam;
use App\Models\ExamTemplate;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Captures an exam's structure as a reusable template.
 *
 * The exam's questions are collapsed into sections — "30 multiple-choice worth
 * 1 mark" — so only the shape is remembered, never the wording or the answers.
 * Saving a template is therefore safe to offer on any exam, published or not.
 */
class SaveExamTemplateFromExamAction
{
    /**
     * @throws ValidationException when the exam has no questions to describe
     */
    public function execute(Exam $exam, User $creator, string $name, ?string $description = null): ExamTemplate
    {
        $questions = $exam->questions()->get();

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'exam' => 'This exam has no questions yet, so there is no structure to save as a template.',
            ]);
        }

        return DB::transaction(function () use ($exam, $creator, $name, $description, $questions) {
            $template = ExamTemplate::create([
                'name' => $name,
                'description' => $description,
                // A teacher-owned template: editable and deletable by its
                // creator, and never shown as a system preset.
                'created_by' => $creator->getKey(),
                'is_system' => false,
                'preset_key' => null,
            ]);

            $sections = $this->collapseIntoSections($questions);

            foreach ($sections as $index => $section) {
                $template->sections()->create([
                    'question_type' => $section['question_type'],
                    'quantity' => $section['quantity'],
                    'points' => $section['points'],
                    'position' => $index + 1,
                ]);
            }

            return $template->load('sections');
        });
    }

    /**
     * Group questions by type and marks, preserving the order in which each
     * combination first appears in the exam.
     *
     * @param  Collection<int, Question>  $questions
     * @return Collection<int, array{question_type: string, points: int, quantity: int, first_position: int}>
     */
    private function collapseIntoSections(Collection $questions): Collection
    {
        return $questions
            ->groupBy(fn (Question $question) => $question->type->value.'|'.$question->points)
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'question_type' => $first->type->value,
                    'points' => (int) $first->points,
                    'quantity' => $group->count(),
                    'first_position' => (int) $group->min('position'),
                ];
            })
            ->sortBy('first_position')
            ->values();
    }
}
