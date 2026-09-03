<?php

namespace App\Actions\Unit;

use App\Models\Course;
use App\Models\Unit;

/**
 * Creates a unit within a course.
 */
class CreateUnitAction
{
    public function execute(Course $course, array $data): Unit
    {
        $data['course_id'] = $course->getKey();
        $data['position'] ??= $this->nextPosition($course);

        return Unit::create($data);
    }

    private function nextPosition(Course $course): int
    {
        return (int) $course->units()->max('position') + 1;
    }
}
