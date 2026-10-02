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
        return $this->statusFor($student, $courseId) === EnrollmentStatus::Active->value;
    }

    /**
     * Current enrollment lifecycle state for this student/course pair. A null
     * result means no historical enrollment exists.
     */
    public function statusFor(User $student, int $courseId): ?string
    {
        $status = Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $courseId)
            ->value('status');

        if ($status instanceof EnrollmentStatus) {
            return $status->value;
        }

        return $status === null ? null : (string) $status;
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
