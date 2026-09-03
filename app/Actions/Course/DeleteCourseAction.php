<?php

namespace App\Actions\Course;

use App\Models\Course;

/**
 * Deletes a course after the caller has been authorized.
 */
class DeleteCourseAction
{
    public function execute(Course $course): void
    {
        $course->delete();
    }
}
