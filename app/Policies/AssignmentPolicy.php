<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use App\Services\EnrollmentService;

class AssignmentPolicy
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
        return $user->isStaff();
    }

    /** Staff manage assignments on their own courses. */
    public function update(User $user, Assignment $assignment): bool
    {
        return $assignment->isManagedBy($user);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return $assignment->isManagedBy($user);
    }

    /** A student may see a published assignment in a course they enrolled in. */
    public function viewAsStudent(User $user, Assignment $assignment): bool
    {
        return $assignment->is_published
            && app(EnrollmentService::class)->isEnrolled($user, $assignment->course_id);
    }

    /** Submitting work: same gate as viewing, plus never after grading. */
    public function submit(User $user, Assignment $assignment): bool
    {
        return $this->viewAsStudent($user, $assignment);
    }

    // Submission-level abilities (view/grade/download) live in
    // AssignmentSubmissionPolicy — Laravel resolves them per model type.
}
