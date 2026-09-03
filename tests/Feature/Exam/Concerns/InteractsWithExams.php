<?php

namespace Tests\Feature\Exam\Concerns;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Option;
use App\Models\Question;
use App\Models\User;

/**
 * Shared helpers for Phase 3 exam feature tests.
 */
trait InteractsWithExams
{
    /**
     * Create a published exam owned by a teacher.
     */
    protected function makeExam(User $teacher, Course $course, array $attributes = []): Exam
    {
        return Exam::factory()->create(array_merge([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
        ], $attributes));
    }

    /**
     * Create a published exam that is valid for publication and attempts.
     */
    protected function makePublishedExam(User $teacher, Course $course, array $attributes = []): Exam
    {
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
        ], $attributes));

        $this->addSingleChoiceQuestion($exam);

        return $exam;
    }

    /**
     * Add a single_choice question with one correct and one incorrect option.
     */
    protected function addSingleChoiceQuestion(Exam $exam, array $attributes = []): Question
    {
        $question = Question::factory()->create(array_merge([
            'exam_id' => $exam->id,
            'position' => $exam->questions()->count() + 1,
            'points' => 1,
        ], $attributes));

        Option::factory()->create([
            'question_id' => $question->id,
            'option_text' => 'The correct answer',
            'is_correct' => true,
            'position' => 1,
        ]);

        Option::factory()->create([
            'question_id' => $question->id,
            'option_text' => 'A wrong answer',
            'is_correct' => false,
            'position' => 2,
        ]);

        return $question->fresh();
    }

    protected function correctOption(Question $question): Option
    {
        return $question->options()->where('is_correct', true)->firstOrFail();
    }
}
