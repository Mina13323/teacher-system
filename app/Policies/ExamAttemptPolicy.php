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
     * Staff-only visibility of the full grading breakdown (per-answer
     * correctness, awarded points and teacher feedback).
     *
     * Deliberately distinct from view(): view() is satisfied by attempt
     * ownership so a student can read their own attempt, but the staff resource
     * (ExamAttemptDetailResource) exposes the answer key and unpublished scores,
     * so it must never be reachable by the student who owns the attempt.
     */
    public function viewStaff(User $user, ExamAttempt $attempt): bool
    {
        return $this->canGrade($user, $attempt);
    }

    /**
     * Staff-only grading of an essay answer. A student must never be able to
     * grade their own attempt, even though they own it.
     */
    public function grade(User $user, ExamAttempt $attempt): bool
    {
        return $this->canGrade($user, $attempt);
    }

    /**
     * Staff-only publication of an attempt's grades. Publication is what makes
     * the score visible to the student, so it can never be self-service.
     */
    public function publishGrades(User $user, ExamAttempt $attempt): bool
    {
        return $this->canGrade($user, $attempt);
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

    /**
     * Who may grade and publish an attempt.
     *
     * Two hard exclusions come first, because the teacher route group is only
     * guarded by `auth:sanctum` (no role middleware) and `view()` is satisfied
     * by attempt ownership:
     *
     *  - the student who owns the attempt can never grade or publish it,
     *  - a user holding the student role can never reach these abilities.
     *
     * Everyone else must be an admin (handled by before()), the teacher who
     * manages the exam, or an assistant holding the operational `exams.view`
     * permission granted by PermissionSeeder.
     */
    private function canGrade(User $user, ExamAttempt $attempt): bool
    {
        if ($attempt->student_id === $user->getKey()) {
            return false;
        }

        if ($user->isStudent()) {
            return false;
        }

        if ($this->canManageExam($user, $attempt)) {
            return true;
        }

        return $user->isAssistant() && $user->hasPermissionTo('exams.view');
    }
}
