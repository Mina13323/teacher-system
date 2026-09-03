<?php

namespace App\Actions\Lesson;

use App\Models\Unit;

/**
 * Reorders the lessons of a unit based on an ordered list of lesson ids.
 */
class ReorderLessonsAction
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function execute(Unit $unit, array $orderedIds): void
    {
        $position = 1;

        foreach ($orderedIds as $id) {
            $unit->lessons()
                ->whereKey($id)
                ->update(['position' => $position++]);
        }
    }
}
