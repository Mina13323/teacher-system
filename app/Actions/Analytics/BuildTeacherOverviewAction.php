<?php

namespace App\Actions\Analytics;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\Competition;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Teacher/staff overview analytics. A teacher sees only their own courses and
 * the data that flows from them; an admin sees the whole platform.
 *
 * Every figure is aggregated in the database. This used to hydrate every
 * enrollment, exam and attempt belonging to the teacher into PHP models and
 * then count them in memory — correct, but on a production dataset it meant
 * loading tens of thousands of rows to render a handful of numbers on the
 * dashboard.
 *
 * @return array<string, mixed>
 */
class BuildTeacherOverviewAction
{
    public function execute(User $user): array
    {
        $courseQuery = $this->scopedCourseQuery($user);
        $courseIds = (clone $courseQuery)->pluck('id');

        $examIds = $courseIds->isEmpty()
            ? collect()
            : Exam::query()->whereIn('course_id', $courseIds)->pluck('id');

        $enrollmentQuery = $courseIds->isEmpty()
            ? null
            : Enrollment::query()
                ->whereIn('course_id', $courseIds)
                ->where('status', EnrollmentStatus::Active->value);

        $attemptsQuery = $examIds->isEmpty()
            ? null
            : ExamAttempt::query()->whereIn('exam_id', $examIds);

        // A handed-in attempt is submitted, grading OR published. Filtering on
        // `submitted` alone silently dropped every attempt that had been through
        // essay grading — which understated every number below.
        $attemptsCount = $attemptsQuery === null
            ? 0
            : (clone $attemptsQuery)->submittedForReporting()->count();

        // Only a final score may be averaged: a `grading` attempt carries a
        // partial percentage, because ungraded essays count as zero against the
        // full point total. Including it would misreport the cohort.
        $scoredCount = $attemptsQuery === null
            ? 0
            : (clone $attemptsQuery)->withFinalScore()->count();

        $averageScore = $scoredCount === 0
            ? null
            : (clone $attemptsQuery)->withFinalScore()->avg('percentage');

        $pendingGrading = $attemptsQuery === null
            ? 0
            : (clone $attemptsQuery)->where('status', ExamAttemptStatus::Grading->value)->count();

        $flagged = $attemptsQuery === null
            ? 0
            : (clone $attemptsQuery)
                ->whereIn('integrity_status', [
                    IntegrityStatus::Flagged->value,
                    IntegrityStatus::Monitoring->value,
                ])
                ->count();

        return [
            'courses_count' => (clone $courseQuery)->count(),
            'published_courses_count' => (clone $courseQuery)
                ->where('status', CourseStatus::Published->value)
                ->count(),
            'enrollments_count' => $enrollmentQuery === null ? 0 : (clone $enrollmentQuery)->count(),
            // Distinct students, not enrollment rows: a student in three courses
            // is one student.
            'students_count' => $enrollmentQuery === null
                ? 0
                : (clone $enrollmentQuery)->distinct()->count('student_id'),
            'exam_count' => $examIds->count(),
            'attempts_count' => $attemptsCount,
            'scored_attempts_count' => $scoredCount,
            'pending_grading_count' => $pendingGrading,
            'average_score' => $averageScore !== null ? (int) round($averageScore) : null,
            'pass_rate' => $this->passRate($attemptsQuery),
            'competitions_count' => $examIds->isEmpty()
                ? 0
                : Competition::query()->whereIn('exam_id', $examIds)->count(),
            'flagged_integrity_count' => $flagged,
        ];
    }

    /**
     * Courses this user's analytics cover. An admin sees everything; an
     * assistant's analytics cover the teacher's courses, matching what the
     * policies let them see.
     *
     * @return Builder<Course>
     */
    protected function scopedCourseQuery(User $user): Builder
    {
        $ownerIds = $user->staffOwnerIds();

        if ($ownerIds === null) {
            return Course::query();
        }

        return Course::query()->whereIn('created_by', $ownerIds);
    }

    /**
     * Share of DEFINITIVE outcomes that met their frozen pass threshold.
     *
     * Uses the same outcome semantics as ExamAttempt::outcome(): flagged
     * attempts (pending review / disqualified) and unpublished or expired
     * attempts have no definitive outcome yet and are excluded from BOTH sides
     * of the ratio — an attempt awaiting review can never be counted as a pass
     * on one screen and a fail on another. The comparison uses
     * COALESCE(raw_percentage, percentage): full precision for new rows, the
     * historical rounded value for legacy rows (identical to old results).
     *
     * @param  Builder<ExamAttempt>|null  $attemptsQuery
     */
    protected function passRate(?Builder $attemptsQuery): ?int
    {
        if ($attemptsQuery === null) {
            return null;
        }

        $definitive = (clone $attemptsQuery)
            ->whereNotNull('percentage')
            ->whereNotNull('pass_percentage')
            ->whereNotNull('grades_published_at')
            ->where('status', '!=', ExamAttemptStatus::Expired->value)
            ->where(function ($query) {
                $query->whereNull('integrity_status')
                    ->orWhere('integrity_status', '!=', IntegrityStatus::Flagged->value);
            });

        $definitiveCount = (clone $definitive)->count();

        if ($definitiveCount === 0) {
            return null;
        }

        $passed = (clone $definitive)
            ->whereRaw('COALESCE(raw_percentage, percentage) >= pass_percentage')
            ->count();

        return (int) round($passed / $definitiveCount * 100);
    }
}
