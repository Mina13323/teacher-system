<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;

class LessonPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('lessons.create');
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->canManageCourse($user, $lesson->unit->course)
            && $user->hasPermissionTo('lessons.update');
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->canManageCourse($user, $lesson->unit->course)
            && $user->hasPermissionTo('lessons.delete');
    }

    private function canManageCourse(User $user, Course $course): bool
    {
        return $user->hasRole('admin') || $course->isOwnedBy($user);
    }
}
