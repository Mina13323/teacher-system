<?php

namespace App\Services\Student;

use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The students a staff member can see and manage: everything for an admin;
 * otherwise the students their teacher created or enrolled in the teacher's
 * courses (with co-teaching, the students shared between all staff).
 *
 * One place for the student list and the bulk renewal, so both always agree.
 */
class ManagedStudentScope
{
    /** @return Builder<User> */
    public function query(User $user): Builder
    {
        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Student->value));

        if ($user->isAdmin()) {
            return $query;
        }

        if (config('app.co_teaching', false) && ($user->isTeacher() || $user->isAssistant())) {
            $staffIds = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                    UserRole::Teacher->value,
                    UserRole::Assistant->value,
                    UserRole::Admin->value,
                ]))
                ->pluck('id');

            return $query->where(fn ($q) => $q->whereIn('created_by', $staffIds)
                ->orWhereNull('created_by')
                ->orWhereHas('enrollments'));
        }

        $teacherId = $user->isTeacher()
            ? $user->getKey()
            : ($user->created_by ?: (User::role(UserRole::Teacher->value)->value('id') ?: $user->getKey()));

        $assistantIds = User::query()
            ->where('created_by', $teacherId)
            ->orWhereHas('roles', fn ($q) => $q->where('name', UserRole::Assistant->value))
            ->pluck('id');

        $enrolledInOwnCourses = Enrollment::query()
            ->whereIn('course_id', Course::query()->where('created_by', $teacherId)->pluck('id'))
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('student_id');

        return $query->where(fn ($q) => $q->where('created_by', $teacherId)
            ->orWhereIn('created_by', $assistantIds)
            ->orWhereIn('id', $enrolledInOwnCourses));
    }
}
