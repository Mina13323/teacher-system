<?php

namespace App\Actions\Exam;

use App\Enums\ExamStatus;
use App\Exceptions\ExamStructureLockedException;
use App\Models\Exam;
use App\Models\ExamTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Appends a template's question skeleton to an exam.
 *
 * The template contributes structure only — question count, type and marks.
 * The teacher then writes the question text and marks the correct answer, which
 * is the entire point: nobody configures points fifty times by hand.
 *
 * Application is additive by design. A teacher who applies two templates gets
 * the union of both, and questions that already exist are left untouched.
 */
class ApplyExamTemplateAction
{
    /**
     * Blank options scaffolded for every multiple-choice question, so the
     * teacher types four choices instead of creating four rows. Publish
     * validation requires at least two options, so four is a safe default that
     * still leaves room to delete.
     */
    public const BLANK_OPTIONS_PER_MCQ = 4;

    /**
     * @return Collection<int, \App\Models\Question> the questions that were created
     *
     * @throws ExamStructureLockedException
     */
    public function execute(Exam $exam, ExamTemplate $template): Collection
    {
        // A published exam may already have live attempts. Appending questions
        // — blank ones at that — would change the paper under students' feet.
        if ($exam->status === ExamStatus::Published) {
            throw new ExamStructureLockedException(
                'A published exam cannot be given new questions. Archive it first, or build the exam from a draft.'
            );
        }

        $template->loadMissing('sections');

        return DB::transaction(function () use ($exam, $template) {
            // New questions continue the existing sequence rather than
            // renumbering what is already there.
            $position = (int) $exam->questions()->max('position');
            $created = collect();

            foreach ($template->sections as $section) {
                for ($index = 0; $index < $section->quantity; $index++) {
                    $position++;

                    $question = $exam->questions()->create([
                        'question_text' => '',
                        'type' => $section->question_type->value,
                        'points' => $section->points,
                        'position' => $position,
                        'reference_answer' => null,
                    ]);

                    // An essay question is free text: it carries an optional
                    // reference answer for the grader and no options at all.
                    if (! $section->isEssay()) {
                        for ($optionPosition = 1; $optionPosition <= self::BLANK_OPTIONS_PER_MCQ; $optionPosition++) {
                            $question->options()->create([
                                'option_text' => '',
                                'is_correct' => false,
                                'position' => $optionPosition,
                            ]);
                        }
                    }

                    $created->push($question);
                }
            }

            return $created;
        });
    }
}
