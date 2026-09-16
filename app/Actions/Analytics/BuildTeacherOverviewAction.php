<?php

namespace App\Actions\Analytics;

use App\Enums\EnrollmentStatus;
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

        // A handed-in attempt is submitted, grading, OR published. Filtering on
        // `submitted` alone silently dropped every attempt that had been
        // through essay grading — which understated every number below.
        $handedIn = $attempts->filter(fn (ExamAttempt $a) => $a->countsAsAttempt());

        // Only a final score may be averaged. A `grading` attempt carries a
        // partial percentage (ungraded essays count as zero), which would drag
        // the average down and misreport the cohort.
        $scored = $handedIn->filter(fn (ExamAttempt $a) => $a->hasFinalScore());

        $avgPercentage = $scored->avg('percentage');
        $passRate = $this->passRate($scored);
        $pendingGrading = $handedIn->filter(fn (ExamAttempt $a) => $a->status?->isGrading());

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
            'attempts_count' => $handedIn->count(),
            'scored_attempts_count' => $scored->count(),
            'pending_grading_count' => $pendingGrading->count(),
            'average_score' => $avgPercentage !== null ? (int) round($avgPercentage) : null,
            'pass_rate' => $passRate !== null ? (int) round($passRate) : null,
            'competitions_count' => $competitions->count(),
            'flagged_integrity_count' => $flagged->count(),
        ];
    }

    protected function scopedCourses(User $user): Collection
    {
        $ownerIds = $user->staffOwnerIds();

        if ($ownerIds === null) {
            return Course::query()->get();
        }

        // An Assistant's analytics cover the Teacher's courses, matching what
        // the policies let them see.
        return Course::query()->whereIn('created_by', $ownerIds)->get();
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

    protected function passRate(Collection $scored): ?float
    {
        if ($scored->isEmpty()) {
            return null;
        }

        // The denominator is attempts with a final score, not every attempt
        // handed in: an attempt still awaiting essay grading has no outcome to
        // pass or fail, and counting it as a failure would understate the rate.
        $passed = $scored->filter(function (ExamAttempt $attempt) {
            return $attempt->pass_percentage !== null
                && $attempt->percentage >= $attempt->pass_percentage;
        })->count();

        return $passed / $scored->count() * 100;
    }
}
