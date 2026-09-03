<?php

namespace App\Actions\Video;

use App\Models\Video;

/**
 * Updates an existing video metadata record.
 */
class UpdateVideoAction
{
    public function execute(Video $video, array $data): Video
    {
        $video->fill($data)->save();

        return $video->refresh();
    }
}
