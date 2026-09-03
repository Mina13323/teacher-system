<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Unit;
use App\Models\User;

class UnitPolicy
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
        return $user->hasPermissionTo('courses.create');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->canManageCourse($user, $unit->course)
            && $user->hasPermissionTo('courses.update');
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->canManageCourse($user, $unit->course)
            && $user->hasPermissionTo('courses.delete');
    }

    private function canManageCourse(User $user, Course $course): bool
    {
        return $user->hasRole('admin') || $course->isOwnedBy($user);
    }
}
