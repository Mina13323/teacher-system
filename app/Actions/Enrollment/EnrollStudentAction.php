<?php

namespace App\Actions\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\CourseNotPublishedException;
use App\Exceptions\DuplicateEnrollmentException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;

/**
 * Enrolls a student in a published course.
 */
class EnrollStudentAction
{
    public function execute(User $student, Course $course): Enrollment
    {
        $course = $course->fresh(['creator']);

        if (! $course->status->isPublished() && ! $student->hasRole('admin')) {
            throw new CourseNotPublishedException();
        }

        $existing = Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->first();

        if ($existing !== null) {
            throw new DuplicateEnrollmentException();
        }

        return Enrollment::create([
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'status' => EnrollmentStatus::Active->value,
            'enrolled_at' => now(),
        ]);
    }
}
