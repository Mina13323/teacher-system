<?php

namespace App\Actions\Analytics;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\CompetitionResult;
use App\Models\Enrollment;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\User;

/**
 * Per-student performance analytics for a teacher (or admin). Aggregates the
 * student's enrollments, progress, exam history, and competition results.
 *
 * @return array<string, mixed>
 */
class BuildStudentAnalyticsAction
{
    public function execute(User $student): array
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with('course')
            ->get();

        $courseIds = $enrollments->pluck('course_id');

        $progress = LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->whereHas('lesson.unit', fn ($q) => $courseIds->isEmpty() ? $q->whereRaw('1 = 0') : $q->whereIn('course_id', $courseIds))
            ->get();

        $attempts = ExamAttempt::query()
            ->where('student_id', $student->getKey())
            ->with('exam')
            ->get();

        $submitted = $attempts->where('status', ExamAttemptStatus::Submitted);

        $avgPercentage = $submitted->whereNotNull('percentage')->avg('percentage');

        $flagged = $attempts->filter(fn (ExamAttempt $a) => in_array($a->integrity_status?->value, [
            IntegrityStatus::Flagged->value,
            IntegrityStatus::Monitoring->value,
        ], true));

        $competitionResults = CompetitionResult::query()
            ->whereHas('participant', fn ($q) => $q->where('student_id', $student->getKey()))
            ->with('competition')
            ->get();

        $history = $attempts
            ->where('status', ExamAttemptStatus::Submitted)
            ->sortByDesc('submitted_at')
            ->values()
            ->map(fn (ExamAttempt $a) => [
                'attempt_id' => $a->id,
                'exam_id' => $a->exam_id,
                'exam_title' => $a->exam?->title,
                'attempt_number' => $a->attempt_number,
                'score' => $a->score,
                'percentage' => $a->percentage,
                'passed' => $a->percentage !== null && $a->pass_percentage !== null
                    ? $a->percentage >= $a->pass_percentage
                    : null,
                'started_at' => $a->started_at?->toISOString(),
                'submitted_at' => $a->submitted_at?->toISOString(),
            ]);

        return [
            'student' => [
                'id' => $student->getKey(),
                'name' => $student->name,
                'display_name' => $student->publicDisplayName(),
                'email' => $student->email,
            ],
            'profile_completed' => $student->isProfileComplete(),
            'enrollments_count' => $enrollments->count(),
            'courses' => $enrollments->map(fn (Enrollment $e) => [
                'course_id' => $e->course_id,
                'title' => $e->course?->title,
                'enrolled_at' => $e->enrolled_at?->toISOString(),
            ])->values(),
            'lessons_completed_count' => $progress->where('completed', true)->count(),
            'attempts_count' => $submitted->count(),
            'average_score' => $avgPercentage !== null ? (int) round($avgPercentage) : null,
            'best_score' => $submitted->whereNotNull('percentage')->max('percentage'),
            'flagged_integrity_count' => $flagged->count(),
            'competition_results' => $competitionResults->map(fn (CompetitionResult $r) => [
                'competition_id' => $r->competition_id,
                'competition_title' => $r->competition?->title,
                'score' => $r->score,
                'percentage' => $r->percentage,
                'rank' => $r->rank,
                'qualified' => $r->qualified,
            ])->values(),
            'history' => $history,
        ];
    }
}
