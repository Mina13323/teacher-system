<?php

namespace App\Actions\Video;

use App\Models\Lesson;

/**
 * Reorders the videos of a lesson based on an ordered list of video ids.
 */
class ReorderVideosAction
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function execute(Lesson $lesson, array $orderedIds): void
    {
        $position = 1;

        foreach ($orderedIds as $id) {
            $lesson->videos()
                ->whereKey($id)
                ->update(['position' => $position++]);
        }
    }
}
