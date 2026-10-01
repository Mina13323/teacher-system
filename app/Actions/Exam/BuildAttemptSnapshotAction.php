<?php

namespace App\Actions\Exam;

use App\Models\Exam;
use App\Models\ExamAttempt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Freezes the structure of an exam into an attempt-specific snapshot so that
 * later teacher edits (renaming a question, changing an option, changing the
 * answer key, reordering) do not alter an in-progress attempt.
 */
class BuildAttemptSnapshotAction
{
    public function execute(ExamAttempt $attempt, Exam $exam): void
    {
        $exam->load('questions.options');

        DB::transaction(function () use ($attempt, $exam) {
            $questions = $exam->questions;

            if ($exam->shuffle_questions) {
                $questions = $questions->shuffle();
            }

            $orderedQuestions = $questions->values();
            $timestamp = now()->toDateTimeString();
            $questionRows = [];

            foreach ($orderedQuestions as $index => $question) {
                $questionRows[] = [
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $question->getKey(),
                    'question_text' => $question->question_text,
                    'question_image_path' => $question->image_path,
                    'question_type' => $question->type?->value ?? 'single_choice',
                    'points' => $question->points,
                    'explanation_enabled' => $question->isMcq() && (bool) $question->explanation_enabled,
                    'explanation_required' => $question->isMcq() && (bool) $question->explanation_enabled && (bool) $question->explanation_required,
                    'position' => $index + 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            // Bound bind counts for SQLite and other database drivers while
            // replacing per-row Eloquent inserts with a small number of batches.
            foreach (array_chunk($questionRows, 50) as $chunk) {
                DB::table('exam_attempt_questions')->insert($chunk);
            }

            $attemptQuestionIds = DB::table('exam_attempt_questions')
                ->where('attempt_id', $attempt->getKey())
                ->pluck('id', 'question_id');

            $optionRows = [];

            foreach ($orderedQuestions as $question) {
                $attemptQuestionId = $attemptQuestionIds->get($question->getKey());

                if ($attemptQuestionId === null) {
                    throw new LogicException('The exam question snapshot could not be created.');
                }

                $options = $question->options;

                if ($exam->shuffle_options) {
                    $options = $options->shuffle();
                }

                foreach ($options->values() as $optionIndex => $option) {
                    $optionRows[] = [
                        'attempt_question_id' => $attemptQuestionId,
                        'option_id' => $option->getKey(),
                        'option_text' => $option->option_text,
                        'is_correct' => $option->is_correct,
                        'position' => $optionIndex + 1,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }
            }

            foreach (array_chunk($optionRows, 100) as $chunk) {
                DB::table('exam_attempt_options')->insert($chunk);
            }
        });
    }
}
