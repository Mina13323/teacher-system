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
        // Teacher and Assistant are operationally equivalent. Admin is granted
        // by before().
        return $user->isStaff();
    }

    /**
     * Viewing a specific course is restricted to the operational staff of the
     * course's owner (the Teacher, or an Assistant working for that Teacher).
     * Public browsing of published courses bypasses this policy.
     */
    public function view(User $user, Course $course): bool
    {
        return $this->canManage($user, $course);
    }

    /**
     * Enrollment management on a course. Assistants have the same operational
     * reach as the Teacher they work for.
     */
    public function manageEnrollments(User $user, Course $course): bool
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
     * A user may manage a course if they own it, or are an Assistant employed
     * by its owner. Admin is granted by before().
     */
    private function canManage(User $user, Course $course): bool
    {
        return $course->isManagedBy($user);
    }
}
