<?php

namespace App\Actions\Video;

use App\Models\Lesson;
use App\Models\Video;
use Illuminate\Support\Str;

/**
 * Creates a video metadata record within a lesson.
 *
 * The storage path is Laravel Filesystem-compatible (e.g. "videos/xxx.mp4").
 * No transcoding or streaming happens here.
 */
class CreateVideoAction
{
    public function execute(Lesson $lesson, array $data): Video
    {
        $data['lesson_id'] = $lesson->getKey();
        $data['storage_path'] ??= $this->defaultStoragePath($data['title']);
        $data['position'] ??= $this->nextPosition($lesson);

        return Video::create($data);
    }

    private function defaultStoragePath(string $title): string
    {
        return 'videos/'.Str::slug($title).'-'.Str::lower(Str::random(10)).'.mp4';
    }

    private function nextPosition(Lesson $lesson): int
    {
        return (int) $lesson->videos()->max('position') + 1;
    }
}
