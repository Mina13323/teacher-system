<?php

namespace App\Policies;

use App\Models\AssignmentSubmission;
use App\Models\User;

/**
 * Authorization for one student's submission: the owning student may read
 * (and download) their own work; staff of the assignment's course may read,
 * download and grade everything; nobody else (teacher-B / student-IDOR tests).
 */
class AssignmentSubmissionPolicy
{
    public function before(?User $user, string $ability): ?bool
    {
        if ($user) {
            return $user->hasRole('admin') ? true : null;
        }

        return null;
    }

    public function view(User $user, AssignmentSubmission $submission): bool
    {
        return $this->ownedByStudent($user, $submission)
            || ($submission->assignment?->isManagedBy($user) ?? false);
    }

    public function gradeSubmission(User $user, AssignmentSubmission $submission): bool
    {
        return $submission->assignment?->isManagedBy($user) ?? false;
    }

    public function viewFile(User $user, AssignmentSubmission $submission): bool
    {
        return $this->view($user, $submission);
    }

    private function ownedByStudent(User $user, AssignmentSubmission $submission): bool
    {
        return $submission->student_id === $user->getKey();
    }
}
