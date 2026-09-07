<?php

namespace App\Actions\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Enrolls a student into a course on behalf of a teacher (or admin). Unlike
 * student self-enrollment, this does not require the course to be published —
 * the teacher is authoritative for their own course. Idempotent on the happy
 * path: an existing enrollment is returned rather than duplicated.
 */
class EnrollStudentToCourseAction
{
    public function execute(User $student, Course $course): Enrollment
    {
        $existing = Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->first();

        if ($existing !== null) {
            if ($existing->status->isActive()) {
                return $existing;
            }

            // Re-activate a previously cancelled/suspended enrollment.
            $existing->status = EnrollmentStatus::Active->value;
            $existing->enrolled_at = $existing->enrolled_at ?? now();
            $existing->save();

            return $existing->fresh();
        }

        return Enrollment::create([
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'status' => EnrollmentStatus::Active->value,
            'enrolled_at' => now(),
        ]);
    }
}
