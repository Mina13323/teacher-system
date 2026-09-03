<?php

namespace App\Actions\Course;

use App\Models\Course;

/**
 * Updates an existing course from validated input.
 */
class UpdateCourseAction
{
    public function execute(Course $course, array $data): Course
    {
        $course->fill($data)->save();

        return $course->refresh();
    }
}
