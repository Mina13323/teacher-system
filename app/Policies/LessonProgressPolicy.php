<?php

namespace App\Policies;

use App\Models\LessonProgress;
use App\Models\User;

class LessonProgressPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function view(User $user, LessonProgress $progress): bool
    {
        return $progress->student_id === $user->getKey();
    }

    /**
     * A student records their own progress; staff may manage it.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('student') || $user->hasPermissionTo('students.manage');
    }

    public function update(User $user, LessonProgress $progress): bool
    {
        return $progress->student_id === $user->getKey();
    }

    public function delete(User $user, LessonProgress $progress): bool
    {
        return $progress->student_id === $user->getKey();
    }
}
