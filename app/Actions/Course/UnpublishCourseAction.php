<?php

namespace App\Actions\Course;

use App\Models\Course;

/**
 * Returns a published course to draft after the caller has been authorized.
 */
class UnpublishCourseAction
{
    public function execute(Course $course): Course
    {
        $course->unpublish();

        return $course->refresh();
    }
}
