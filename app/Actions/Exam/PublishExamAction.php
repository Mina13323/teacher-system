<?php

namespace App\Actions\Exam;

use App\Enums\ExamStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\ExamNotReadyToPublishException;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Notifications\ExamPublishedNotification;

/**
 * Validates and publishes an exam.
 *
 * An exam is publishable only when:
 *  - it belongs to a course,
 *  - it has at least one question,
 *  - every choice question has at least two options,
 *  - every single_choice question has exactly one correct option,
 *  - every essay question is worth more than zero points (essays carry no
 *    options and no answer key — they are graded manually),
 *  - duration_minutes > 0, 0 <= pass_percentage <= 100, max_attempts >= 1.
 */
class PublishExamAction
{
    public function execute(Exam $exam): Exam
    {
        $this->assertValid($exam);

        $exam->status = ExamStatus::Published->value;
        $exam->save();

        $this->notifyEnrolledStudents($exam);

        return $exam->fresh();
    }

    /**
     * Notify enrolled students (in the exam's course) that the exam is available.
     * The notification contains no questions or answer key.
     */
    private function notifyEnrolledStudents(Exam $exam): void
    {
        Enrollment::query()
            ->where('course_id', $exam->course_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->with('student')
            ->get()
            ->each(function (Enrollment $enrollment) use ($exam) {
                $enrollment->student?->notify(new ExamPublishedNotification($exam));
            });
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
            // An essay question is free-text: it has no options and no answer
            // key, so the MCQ shape checks must not apply to it. All that
            // matters is that it carries a mark a grader can award against.
            if ($question->isEssay()) {
                if ($question->points <= 0) {
                    throw new ExamNotReadyToPublishException(
                        'Essay question '.$question->id.' must be worth more than zero points.'
                    );
                }

                continue;
            }

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
