<?php

namespace App\Actions\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Teacher-authorized enrollment; replays are safe and cancelled rows reactivate. */
class EnrollStudentToCourseAction
{
    public function execute(User $student, Course $course): Enrollment
    {
        if (! $student->isStudent()) {
            throw new AuthorizationException('Only student accounts can be enrolled in a course.');
        }

        try {
            return DB::transaction(function () use ($student, $course): Enrollment {
                $existing = Enrollment::query()
                    ->where('student_id', $student->getKey())
                    ->where('course_id', $course->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->activate($existing);
                }

                return Enrollment::create([
                    'student_id' => $student->getKey(),
                    'course_id' => $course->getKey(),
                    'status' => EnrollmentStatus::Active->value,
                    'enrolled_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            // Concurrent first enrollments can both observe no row. The DB
            // unique key decides the winner; the losing request then adopts the
            // single row rather than failing or creating a duplicate.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = Enrollment::query()
                ->where('student_id', $student->getKey())
                ->where('course_id', $course->getKey())
                ->first();

            if ($existing) {
                return DB::transaction(fn () => $this->activate(
                    Enrollment::query()->whereKey($existing->getKey())->lockForUpdate()->firstOrFail()
                ));
            }

            throw $exception;
        }
    }

    private function activate(Enrollment $enrollment): Enrollment
    {
        if (! $enrollment->status->isActive()) {
            // Reactivation restores access without rewriting the original
            // enrollment date or historical course-completion timestamp.
            $enrollment->status = EnrollmentStatus::Active;
            $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
            $enrollment->save();
        }

        return $enrollment;
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
