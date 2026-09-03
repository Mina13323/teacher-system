<?php

namespace App\Actions\Progress;

use App\Enums\CourseStatus;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;

/**
 * Creates or updates a student's progress for a lesson and enforces the
 * progress state rules:
 *
 *   - 100%  -> completed = true, completed_at = now
 *   - < 100% -> completed = false, completed_at = null
 */
class UpdateLessonProgressAction
{
    public function execute(User $student, Lesson $lesson, array $data): LessonProgress
    {
        $progress = LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->first();

        $progress ??= new LessonProgress([
            'student_id' => $student->getKey(),
            'lesson_id' => $lesson->getKey(),
        ]);

        $progress->progress_percentage = (int) $data['progress_percentage'];
        $progress->last_position_seconds = (int) ($data['last_position_seconds'] ?? 0);

        if (array_key_exists('completed', $data)) {
            $progress->completed = (bool) $data['completed'];
        }

        $this->applyStateRules($progress);

        $progress->save();

        return $progress->fresh();
    }

    /**
     * Enforce the completed / completed_at invariant.
     */
    private function applyStateRules(LessonProgress $progress): void
    {
        $percentage = (int) $progress->progress_percentage;

        if ($percentage >= 100 || $progress->completed) {
            $progress->completed = true;
            $progress->progress_percentage = 100;
            $progress->completed_at = $progress->completed_at ?? now();
        } else {
            $progress->completed = false;
            $progress->completed_at = null;
        }
    }
}
