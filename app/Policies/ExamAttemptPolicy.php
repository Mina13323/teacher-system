<?php

namespace App\Policies;

use App\Models\ExamAttempt;
use App\Models\User;

class ExamAttemptPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    /**
     * A student may view their own attempt. A teacher may view attempts for
     * an exam they manage.
     */
    public function view(User $user, ExamAttempt $attempt): bool
    {
        if ($attempt->student_id === $user->getKey()) {
            return true;
        }

        return $this->canManageExam($user, $attempt);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('student');
    }

    /**
     * A student may answer only their own in-progress attempt.
     */
    public function update(User $user, ExamAttempt $attempt): bool
    {
        return $attempt->student_id === $user->getKey();
    }

    /**
     * A student may record integrity events only for their own attempt.
     */
    public function recordEvent(User $user, ExamAttempt $attempt): bool
    {
        return $attempt->student_id === $user->getKey();
    }

    /**
     * Teacher-only visibility of integrity evidence (risk score, events, reviews).
     */
    public function viewIntegrity(User $user, ExamAttempt $attempt): bool
    {
        return $this->canManageExam($user, $attempt);
    }

    /**
     * Teacher-only review of an attempt's integrity evidence.
     */
    public function review(User $user, ExamAttempt $attempt): bool
    {
        return $this->canManageExam($user, $attempt);
    }

    /**
     * Teacher-only visibility of attempt detail.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('students.view');
    }

    private function canManageExam(User $user, ExamAttempt $attempt): bool
    {
        return $user->hasRole('admin') || $attempt->exam->isManagedBy($user);
    }
}
