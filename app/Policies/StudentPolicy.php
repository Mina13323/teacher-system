<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Authorization for student account management in a teacher-owned LMS.
 *
 * A teacher may manage a student account if:
 *   - they created the student account, OR
 *   - the student is actively enrolled in at least one course the teacher owns.
 *
 * Administrators may manage any account. A teacher can never reach a student
 * that is neither one they created nor enrolled in one of their courses, which
 * prevents cross-teacher access to another teacher's students.
 */
class StudentPolicy
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
        return $user->hasRole('teacher')
            || $user->hasRole('admin')
            || $user->hasPermissionTo('students.view');
    }

    public function view(User $user, User $student): bool
    {
        // A teacher may view a specific student only if they actually manage that
        // student (created the account or own a course they are enrolled in).
        // The broad `students.view` permission is used by viewAny for listing,
        // which the controller additionally scopes.
        return $this->managesStudent($user, $student);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('students.create');
    }

    public function update(User $user, User $student): bool
    {
        return $this->manage($user, $student)
            && $user->hasPermissionTo('students.manage');
    }

    /**
     * Account-level actions (activate / deactivate / reset password / notify).
     */
    public function manage(User $user, User $student): bool
    {
        return $this->managesStudent($user, $student)
            && $user->hasPermissionTo('students.manage');
    }

    public function delete(User $user, User $student): bool
    {
        // Hard deletion of an account is admin-only. Everything else should use
        // deactivation to preserve historical data.
        return $user->hasRole('admin');
    }

    /**
     * Whether the teacher manages this student (created them or owns a course
     * the student is enrolled in).
     */
    private function managesStudent(User $user, User $student): bool
    {
        if (! $user->hasRole('teacher')) {
            return false;
        }

        if ((int) $student->created_by === (int) $user->getKey()) {
            return true;
        }

        $courseIds = Course::query()
            ->where('created_by', $user->getKey())
            ->pluck('id');

        return Enrollment::query()
            ->where('student_id', $student->getKey())
            ->whereIn('course_id', $courseIds)
            ->where('status', \App\Enums\EnrollmentStatus::Active->value)
            ->exists();
    }
}
