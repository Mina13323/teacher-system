<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('students.view');
    }

    /**
     * A user may only view their own enrollments.
     */
    public function view(User $user, Enrollment $enrollment): bool
    {
        return $enrollment->student_id === $user->getKey();
    }

    /**
     * A student may enrol themselves, and staff may manage enrollments.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('student') || $user->hasPermissionTo('students.manage');
    }

    /**
     * Only staff may modify an enrollment.
     */
    public function update(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermissionTo('students.manage');
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermissionTo('students.manage');
    }
}
