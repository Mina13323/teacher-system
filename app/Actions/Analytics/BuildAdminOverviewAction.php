<?php

namespace App\Actions\Analytics;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\Competition;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\User;

/**
 * System-level overview for administrators.
 *
 * @return array<string, mixed>
 */
class BuildAdminOverviewAction
{
    public function execute(): array
    {
        $teachers = User::whereHas('roles', fn ($q) => $q->where('name', 'teacher'))->count();
        $students = User::whereHas('roles', fn ($q) => $q->where('name', 'student'))->count();

        $courses = Course::query()->count();
        $enrollments = Enrollment::query()->where('status', EnrollmentStatus::Active->value)->count();

        // Handed-in = submitted, grading or published. The previous
        // `where('status', Submitted)` excluded every attempt that had been
        // through essay grading, so the admin dashboard under-reported both the
        // attempt volume and the platform average.
        $attemptsCount = ExamAttempt::query()->submittedForReporting()->count();
        $avgPercentage = ExamAttempt::query()->withFinalScore()->avg('percentage');
        $pendingGrading = ExamAttempt::query()
            ->where('status', ExamAttemptStatus::Grading->value)
            ->count();

        $flagged = ExamAttempt::query()
            ->whereIn('integrity_status', [IntegrityStatus::Flagged->value, IntegrityStatus::Monitoring->value])
            ->count();

        $competitions = Competition::query()->count();

        return [
            'teachers_count' => $teachers,
            'students_count' => $students,
            'courses_count' => $courses,
            'active_enrollments_count' => $enrollments,
            'submitted_attempts_count' => (int) $attemptsCount,
            'pending_grading_count' => (int) $pendingGrading,
            'average_score' => $avgPercentage !== null ? (int) round($avgPercentage) : null,
            'flagged_integrity_count' => (int) $flagged,
            'competitions_count' => $competitions,
            // Active attempt count (concurrency/health signal).
            'active_attempts_count' => ExamAttempt::where('status', ExamAttemptStatus::InProgress)->count(),
        ];
    }
}
