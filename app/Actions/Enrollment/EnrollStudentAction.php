<?php

namespace App\Actions\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Exceptions\CourseNotPublishedException;
use App\Exceptions\DuplicateEnrollmentException;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Student self-enrollment in a published course.
 *
 * A historical cancelled enrollment is deliberately not self-reactivated:
 * cancellation is staff-controlled, and the course catalog tells the student
 * to contact their teacher. Staff can restore the same record without losing
 * prior progress or attempt history.
 */
class EnrollStudentAction
{
    public function execute(User $student, Course $course): Enrollment
    {
        if (! $student->isStudent() || ! $student->isActive() || ! $student->hasActiveAccess()) {
            throw new AuthorizationException('Only students with active access can enroll in courses.');
        }

        if (! $student->canAccessLessons() && ! $student->canTakeExams()) {
            throw new AuthorizationException('This student account has no active course capabilities.');
        }

        $course = $course->fresh(['creator']);

        if (! $course || ! $course->status->isPublished()) {
            throw new CourseNotPublishedException();
        }

        try {
            return DB::transaction(function () use ($student, $course): Enrollment {
                $existing = Enrollment::query()
                    ->where('student_id', $student->getKey())
                    ->where('course_id', $course->getKey())
                    ->lockForUpdate()
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
            });
        } catch (QueryException $exception) {
            // The database unique key (student_id, course_id) is the final
            // race-safe guard if two enrollment requests arrive together.
            if ($this->isUniqueViolation($exception)) {
                throw new DuplicateEnrollmentException();
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23505'
            || (int) $driverCode === 1062
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed')
            || str_contains($exception->getMessage(), 'unique constraint');
    }
}
