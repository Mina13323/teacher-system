<?php

namespace App\Actions\Lesson;

use App\Models\Lesson;
use App\Models\Unit;
use Illuminate\Support\Str;

/**
 * Creates a lesson within a unit.
 */
class CreateLessonAction
{
    public function execute(Unit $unit, array $data): Lesson
    {
        $data['unit_id'] = $unit->getKey();
        $data['slug'] ??= $this->uniqueSlug($unit, $data['title']);
        $data['position'] ??= $this->nextPosition($unit);

        return Lesson::create($data);
    }

    private function uniqueSlug(Unit $unit, string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $counter = 1;

        while ($unit->lessons()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function nextPosition(Unit $unit): int
    {
        return (int) $unit->lessons()->max('position') + 1;
    }
}
