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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Per-student performance analytics for a teacher (or admin), and for the
 * student themselves through the `analytics/me` endpoint.
 *
 * Counts and averages are aggregated in the database. Only the recent history
 * window is hydrated, so a student with a long attempt record does not pull
 * every row into memory to render a table.
 *
 * @return array<string, mixed>
 */
class BuildStudentAnalyticsAction
{
    /**
     * How many attempts the history table shows.
     *
     * The endpoint returns a plain array rather than a paginator, so the window
     * is what keeps the payload bounded. `attempts_count` still reports the true
     * total, so nothing is silently hidden from the summary figures.
     */
    public const HISTORY_LIMIT = 50;

    /**
     * @param  bool  $revealUnpublishedScores  true only for staff viewers. A
     *        student reading their own analytics must not see a score before
     *        the teacher publishes grades — the same gate the attempt and
     *        result resources apply.
     *
     * @return array<string, mixed>
     */
    public function execute(User $student, bool $revealUnpublishedScores = false): array
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $student->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with('course')
            ->get();

        $courseIds = $enrollments->pluck('course_id');

        $attemptsQuery = ExamAttempt::query()->where('student_id', $student->getKey());

        // Handed-in attempts (submitted, grading, published) drive the counts.
        // The old `where('status', Submitted)` dropped every attempt that had
        // been through essay grading, so a student who sat an essay exam saw an
        // empty exam history even after their grade was published.
        $attemptsCount = (clone $attemptsQuery)->submittedForReporting()->count();

        // Only a final score may be averaged; a `grading` attempt carries a
        // partial percentage.
        $scoredQuery = fn (): Builder => (clone $attemptsQuery)->withFinalScore();

        // A student may only see the results that have actually been released.
        $visibleScoredQuery = function () use ($scoredQuery, $revealUnpublishedScores): Builder {
            $query = $scoredQuery();

            return $revealUnpublishedScores
                ? $query
                : $query->whereNotNull('grades_published_at');
        };

        $averageScore = $visibleScoredQuery()->avg('percentage');
        $bestScore = $visibleScoredQuery()->max('percentage');

        $competitionResults = CompetitionResult::query()
            ->whereHas('participant', fn ($q) => $q->where('student_id', $student->getKey()))
            ->with('competition')
            ->get();

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
            'lessons_completed_count' => $this->completedLessonCount($student, $courseIds),
            'attempts_count' => $attemptsCount,
            'pending_grading_count' => (clone $attemptsQuery)
                ->where('status', ExamAttemptStatus::Grading->value)
                ->count(),
            'awaiting_publication_count' => (clone $attemptsQuery)
                ->submittedForReporting()
                ->whereNull('grades_published_at')
                ->count(),
            'average_score' => $averageScore !== null ? (int) round($averageScore) : null,
            'best_score' => $bestScore,
            'flagged_integrity_count' => (clone $attemptsQuery)
                ->whereIn('integrity_status', [
                    IntegrityStatus::Flagged->value,
                    IntegrityStatus::Monitoring->value,
                ])
                ->count(),
            'competition_results' => $competitionResults->map(fn (CompetitionResult $r) => [
                'competition_id' => $r->competition_id,
                'competition_title' => $r->competition?->title,
                'score' => $r->score,
                'percentage' => $r->percentage,
                'rank' => $r->rank,
                'qualified' => $r->qualified,
            ])->values(),
            'history' => $this->history($attemptsQuery, $revealUnpublishedScores),
        ];
    }

    /**
     * The most recent handed-in attempts, newest first.
     *
     * @param  Builder<ExamAttempt>  $attemptsQuery
     * @return Collection<int, array<string, mixed>>
     */
    private function history(Builder $attemptsQuery, bool $revealUnpublishedScores): Collection
    {
        return (clone $attemptsQuery)
            ->submittedForReporting()
            ->with('exam')
            ->latest('submitted_at')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (ExamAttempt $a) use ($revealUnpublishedScores) {
                // The score is written to the row at submit time, but it must
                // not reach the student until grades are published.
                $released = $revealUnpublishedScores || $a->resultIsPublished();
                $percentage = $released && $a->hasFinalScore() ? $a->percentage : null;

                return [
                    'attempt_id' => $a->id,
                    'exam_id' => $a->exam_id,
                    'exam_title' => $a->exam?->title,
                    'attempt_number' => $a->attempt_number,
                    'status' => $a->status?->value,
                    'grades_published' => $a->resultIsPublished(),
                    'score' => $released ? $a->score : null,
                    'percentage' => $percentage,
                    'passed' => $percentage !== null && $a->pass_percentage !== null
                        ? $percentage >= $a->pass_percentage
                        : null,
                    'started_at' => $a->started_at?->toISOString(),
                    'submitted_at' => $a->submitted_at?->toISOString(),
                ];
            })
            ->values();
    }

    /**
     * Lessons the student has completed inside the courses they are enrolled in.
     *
     * @param  Collection<int, int>  $courseIds
     */
    private function completedLessonCount(User $student, Collection $courseIds): int
    {
        if ($courseIds->isEmpty()) {
            return 0;
        }

        return LessonProgress::query()
            ->where('student_id', $student->getKey())
            ->where('completed', true)
            ->whereHas('lesson.unit', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->count();
    }
}
