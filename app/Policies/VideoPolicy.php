<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;
use App\Models\Video;

class VideoPolicy
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

    public function update(User $user, Video $video): bool
    {
        return $this->canManageCourse($user, $video->lesson->unit->course)
            && $user->hasPermissionTo('lessons.update');
    }

    public function delete(User $user, Video $video): bool
    {
        return $this->canManageCourse($user, $video->lesson->unit->course)
            && $user->hasPermissionTo('lessons.delete');
    }

    private function canManageCourse(User $user, Course $course): bool
    {
        return $user->hasRole('admin') || $course->isOwnedBy($user);
    }
}
