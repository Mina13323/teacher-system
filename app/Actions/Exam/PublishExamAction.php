<?php

namespace App\Actions\Exam;

use App\Enums\ExamStatus;
use App\Exceptions\ExamNotReadyToPublishException;
use App\Models\Exam;

/**
 * Validates and publishes an exam.
 *
 * An exam is publishable only when:
 *  - it belongs to a course,
 *  - it has at least one question,
 *  - every question has at least two options,
 *  - every single_choice question has exactly one correct option,
 *  - duration_minutes > 0, 0 <= pass_percentage <= 100, max_attempts >= 1.
 */
class PublishExamAction
{
    public function execute(Exam $exam): Exam
    {
        $this->assertValid($exam);

        $exam->status = ExamStatus::Published->value;
        $exam->save();

        return $exam->fresh();
    }

    public function assertValid(Exam $exam): void
    {
        if ($exam->duration_minutes <= 0) {
            throw new ExamNotReadyToPublishException('Duration must be greater than zero.');
        }

        if ($exam->pass_percentage < 0 || $exam->pass_percentage > 100) {
            throw new ExamNotReadyToPublishException('Pass percentage must be between 0 and 100.');
        }

        if ($exam->max_attempts < 1) {
            throw new ExamNotReadyToPublishException('An exam must allow at least one attempt.');
        }

        $questions = $exam->questions()->with('options')->get();

        if ($questions->isEmpty()) {
            throw new ExamNotReadyToPublishException('An exam must contain at least one question.');
        }

        foreach ($questions as $question) {
            if ($question->options->count() < 2) {
                throw new ExamNotReadyToPublishException(
                    'Question '.$question->id.' must have at least two options.'
                );
            }

            if (! $question->hasValidSingleCorrectOption()) {
                throw new ExamNotReadyToPublishException(
                    'Question '.$question->id.' must have exactly one correct option.'
                );
            }
        }
    }
}
