<?php

namespace App\Actions\Exam;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Freezes the structure of an exam into an attempt-specific snapshot so that
 * later teacher edits (renaming a question, changing an option, changing the
 * answer key, reordering) do not alter an in-progress attempt.
 *
 * Randomization is applied a single time here, at attempt start. The stored
 * `position` reflects the order presented to the student; it is never
 * re-randomized on later reads.
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

            foreach ($orderedQuestions as $index => $question) {
                $attemptQuestion = ExamAttemptQuestion::create([
                    'attempt_id' => $attempt->getKey(),
                    'question_id' => $question->getKey(),
                    'question_text' => $question->question_text,
                    'points' => $question->points,
                    'position' => $index + 1,
                ]);

                $options = $question->options;

                if ($exam->shuffle_options) {
                    $options = $options->shuffle();
                }

                foreach ($options->values() as $optionIndex => $option) {
                    ExamAttemptOption::create([
                        'attempt_question_id' => $attemptQuestion->getKey(),
                        'option_id' => $option->getKey(),
                        'option_text' => $option->option_text,
                        'is_correct' => $option->is_correct,
                        'position' => $optionIndex + 1,
                    ]);
                }
            }
        });
    }
}
