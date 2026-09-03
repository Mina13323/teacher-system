<?php

namespace App\Actions\Exam;

use App\Enums\ExamStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\User;

/**
 * Creates an exam for a course in draft state.
 */
class CreateExamAction
{
    public function execute(Course $course, User $creator, array $data): Exam
    {
        return Exam::create(array_merge($data, [
            'course_id' => $course->getKey(),
            'created_by' => $creator->getKey(),
            'status' => ExamStatus::Draft->value,
        ]));
    }
}
