<?php

namespace App\Actions\Lesson;

use App\Models\Lesson;

/**
 * Updates an existing lesson from validated input.
 */
class UpdateLessonAction
{
    public function execute(Lesson $lesson, array $data): Lesson
    {
        $lesson->fill($data)->save();

        return $lesson->refresh();
    }
}
