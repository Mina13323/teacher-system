<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;

class ExamPolicy
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
        return $user->hasRole('teacher') || $user->hasRole('admin');
    }

    public function view(User $user, Exam $exam): bool
    {
        return $this->canManage($user, $exam);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('exams.create');
    }

    public function update(User $user, Exam $exam): bool
    {
        return $this->canManage($user, $exam) && $user->hasPermissionTo('exams.update');
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $this->canManage($user, $exam) && $user->hasPermissionTo('exams.delete');
    }

    public function viewAttempts(User $user, Exam $exam): bool
    {
        return $this->canManage($user, $exam) && $user->hasPermissionTo('students.view');
    }

    private function canManage(User $user, Exam $exam): bool
    {
        return $user->hasRole('admin') || $exam->isManagedBy($user);
    }
}
