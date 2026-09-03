<?php

namespace App\Actions\Unit;

use App\Models\Course;

/**
 * Reorders the units of a course based on an ordered list of unit ids.
 */
class ReorderUnitsAction
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function execute(Course $course, array $orderedIds): void
    {
        $position = 1;

        foreach ($orderedIds as $id) {
            $course->units()
                ->whereKey($id)
                ->update(['position' => $position++]);
        }
    }
}
