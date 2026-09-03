<?php

namespace App\Actions\Progress;

use App\Models\LessonProgress;
use App\Models\Unit;
use App\Models\User;

/**
 * Computes a student's aggregate progress for a single unit.
 */
class CalculateUnitProgressAction
{
    /**
     * @return array{total: int, completed: int, percentage: int}
     */
    public function forUnit(Unit $unit, User $student): array
    {
        $total = $unit->lessons()->where('is_published', true)->count();

        if ($total === 0) {
            return ['total' => 0, 'completed' => 0, 'percentage' => 0];
        }

        $lessonIds = $unit->lessons()->where('is_published', true)->pluck('id');

        $completed = LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->where('completed', true)
            ->whereIn('lesson_id', $lessonIds)
            ->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'percentage' => $total === 0 ? 0 : (int) round($completed / $total * 100),
        ];
    }
}
