<?php

namespace App\Actions\Course;

use App\Enums\CourseStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a course owned by the provided teacher/admin.
 */
class CreateCourseAction
{
    public function execute(User $creator, array $data): Course
    {
        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['title']);
        $data['status'] = $data['status'] ?? CourseStatus::Draft->value;
        $data['created_by'] = $creator->getKey();

        return Course::create($data);
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $counter = 1;

        while (Course::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
