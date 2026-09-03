<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Shared enrollment lookups used across student-facing exam features.
 */
class EnrollmentService
{
    /**
     * Whether the student has an active enrollment in the given course.
     */
    public function isEnrolled(User $student, int $courseId): bool
    {
        return Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $courseId)
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();
    }

    /**
     * The ids of all courses the student is actively enrolled in.
     *
     * @return array<int>
     */
    public function enrolledCourseIds(User $student): array
    {
        return Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('course_id')
            ->all();
    }
}
