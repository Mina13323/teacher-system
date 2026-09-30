<?php

namespace App\Actions\Certificate;

use App\Exceptions\InvalidAttemptStateException;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Issues (or returns the existing) course-completion certificate.
 *
 * ELIGIBILITY (documented rule): the student must have completed EVERY
 * published lesson in the course (`lesson_progress.completed = true` for each).
 * Unpublished/soft-deleted lessons and exams do not gate certificates; exam
 * results remain their own recorded outcomes.
 *
 * Idempotent: one certificate per (student, course) — enforced by a DB unique
 * pair. Re-issuing returns the SAME certificate (same verification code); a
 * verification code is never regenerated.
 */
class IssueCertificateAction
{
    /**
     * @throws InvalidAttemptStateException when the student is not yet eligible
     */
    public function execute(User $student, Course $course): Certificate
    {
        $existing = Certificate::query()
            ->where('student_id', $student->getKey())
            ->where('course_id', $course->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        if (! $this->isEligible($student, $course)) {
            throw new InvalidAttemptStateException(
                'Course not complete yet: finish every published lesson to earn the certificate.'
            );
        }

        $enrollment = $student->enrollments()
            ->where('course_id', $course->getKey())
            ->first();

        return Certificate::create([
            'student_id' => $student->getKey(),
            'course_id' => $course->getKey(),
            'enrollment_id' => $enrollment?->getKey(),
            'code' => $this->uniqueCode(),
            'issued_at' => now(),
            'metadata' => [
                'completed_lessons' => $this->publishedLessonCount($course),
            ],
        ]);
    }

    public function isEligible(User $student, Course $course): bool
    {
        // Lessons hang off units (lessons.course_id does not exist).
        $lessonIds = $course->lessons()
            ->where('is_published', true)
            ->pluck('lessons.id');

        if ($lessonIds->isEmpty()) {
            return false;
        }

        $completed = LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->whereIn('lesson_id', $lessonIds)
            ->where('completed', true)
            ->count();

        return $completed === $lessonIds->count();
    }

    private function publishedLessonCount(Course $course): int
    {
        return $course->lessons()
            ->where('is_published', true)
            ->count();
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'CERT-'.Str::upper(Str::random(24));
        } while (Certificate::query()->where('code', $code)->exists());

        return $code;
    }
}
