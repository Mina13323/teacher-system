<?php

namespace App\Actions\Course;

use App\Models\Course;
use App\Models\User;

/**
 * Publishes a course after the caller has been authorized.
 */
class PublishCourseAction
{
    public function execute(Course $course): Course
    {
        $course->publish();

        return $course->refresh();
    }
}
