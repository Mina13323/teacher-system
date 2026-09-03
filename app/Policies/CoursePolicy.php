<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    /**
     * Administrators are granted every capability by default.
     */
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole('teacher') || $user->hasRole('admin');
    }

    /**
     * Viewing a specific course is restricted to its owner or an admin.
     * Public browsing of published courses bypasses this policy.
     */
    public function view(User $user, Course $course): bool
    {
        return $this->canManage($user, $course);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('courses.create');
    }

    public function update(User $user, Course $course): bool
    {
        return $this->canManage($user, $course) && $user->hasPermissionTo('courses.update');
    }

    public function delete(User $user, Course $course): bool
    {
        return $this->canManage($user, $course) && $user->hasPermissionTo('courses.delete');
    }

    /**
     * A user may manage a course only if they own it or are an admin.
     */
    private function canManage(User $user, Course $course): bool
    {
        return $user->hasRole('admin') || $course->isOwnedBy($user);
    }
}
