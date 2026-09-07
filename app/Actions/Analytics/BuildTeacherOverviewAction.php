<?php

namespace App\Actions\Analytics;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\Competition;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Teacher/USER overview analytics. A teacher sees only their own courses and any
 * data that flows from them; an admin sees the whole platform.
 *
 * @return array<string, mixed>
 */
class BuildTeacherOverviewAction
{
    public function execute(User $user): array
    {
        $courses = $this->scopedCourses($user);
        $courseIds = $courses->pluck('id');

        $enrollments = $this->scopedEnrollments($user, $courseIds);
        $studentIds = $enrollments->pluck('student_id')->unique()->values();

        $exams = $this->scopedExams($user, $courseIds);
        $examIds = $exams->pluck('id');

        $attempts = $this->scopedAttempts($user, $examIds);
        $submitted = $attempts->where('status', ExamAttemptStatus::Submitted);

        $avgPercentage = $submitted->whereNotNull('percentage')->avg('percentage');
        $passRate = $this->passRate($submitted);

        $competitions = $this->scopedCompetitions($user, $examIds);

        $flagged = $attempts->filter(fn (ExamAttempt $a) => in_array($a->integrity_status?->value, [
            IntegrityStatus::Flagged->value,
            IntegrityStatus::Monitoring->value,
        ], true));

        return [
            'courses_count' => $courses->count(),
            'published_courses_count' => $courses->where('status', \App\Enums\CourseStatus::Published)->count(),
            'enrollments_count' => $enrollments->count(),
            'students_count' => $studentIds->count(),
            'exam_count' => $exams->count(),
            'attempts_count' => $submitted->count(),
            'average_score' => $avgPercentage !== null ? (int) round($avgPercentage) : null,
            'pass_rate' => $passRate !== null ? (int) round($passRate) : null,
            'competitions_count' => $competitions->count(),
            'flagged_integrity_count' => $flagged->count(),
        ];
    }

    protected function scopedCourses(User $user): Collection
    {
        if ($user->isAdmin()) {
            return Course::query()->get();
        }

        return Course::query()->where('created_by', $user->getKey())->get();
    }

    protected function scopedEnrollments(User $user, Collection $courseIds): Collection
    {
        if ($courseIds->isEmpty()) {
            return collect();
        }

        return Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', EnrollmentStatus::Active->value)
            ->get();
    }

    protected function scopedExams(User $user, Collection $courseIds): Collection
    {
        if ($courseIds->isEmpty()) {
            return collect();
        }

        return Exam::query()->whereIn('course_id', $courseIds)->get();
    }

    protected function scopedAttempts(User $user, Collection $examIds): Collection
    {
        if ($examIds->isEmpty()) {
            return collect();
        }

        return ExamAttempt::query()->whereIn('exam_id', $examIds)->get();
    }

    protected function scopedCompetitions(User $user, Collection $examIds): Collection
    {
        if ($examIds->isEmpty()) {
            return collect();
        }

        return Competition::query()->whereIn('exam_id', $examIds)->get();
    }

    protected function passRate(Collection $submitted): ?float
    {
        if ($submitted->isEmpty()) {
            return null;
        }

        $passed = $submitted->filter(function (ExamAttempt $attempt) {
            return $attempt->percentage !== null
                && $attempt->pass_percentage !== null
                && $attempt->percentage >= $attempt->pass_percentage;
        })->count();

        return $passed / $submitted->count() * 100;
    }
}
