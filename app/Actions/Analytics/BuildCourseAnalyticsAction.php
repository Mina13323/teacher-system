<?php

namespace App\Actions\Analytics;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Per-course analytics for a teacher (or admin). Aggregates enrollment,
 * progress, performance and competition activity for a single course.
 *
 * Averages, groupings and counts are pushed into SQL. The previous version
 * loaded every attempt, enrollment and progress row for the course into PHP and
 * aggregated there, which does not scale once a course has a real cohort.
 *
 * @return array<string, mixed>
 */
class BuildCourseAnalyticsAction
{
    public function execute(User $user, Course $course): array
    {
        $enrollmentQuery = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value);

        $studentIds = (clone $enrollmentQuery)->distinct()->pluck('student_id');
        $lessons = $course->lessons()->get();
        $lessonIds = $lessons->pluck('id');

        $exams = Exam::query()->where('course_id', $course->getKey())->get();
        $examIds = $exams->pluck('id');

        $attemptsQuery = $examIds->isEmpty()
            ? null
            : ExamAttempt::query()->whereIn('exam_id', $examIds);

        // Handed-in = submitted, grading or published. The old
        // `where('status', Submitted)` dropped every essay exam once it was
        // graded, so a course full of essay exams reported zero attempts.
        $attemptsCount = $attemptsQuery === null
            ? 0
            : (clone $attemptsQuery)->submittedForReporting()->count();

        // Only a final score feeds averages, leaderboards and weak areas: a
        // `grading` attempt carries a partial percentage.
        $scoredCount = $attemptsQuery === null
            ? 0
            : (clone $attemptsQuery)->withFinalScore()->count();

        $averageScore = $scoredCount === 0
            ? null
            : (clone $attemptsQuery)->withFinalScore()->avg('percentage');

        return [
            'course' => [
                'id' => $course->getKey(),
                'title' => $course->title,
                'status' => $course->status?->value,
            ],
            'enrollments_count' => (clone $enrollmentQuery)->count(),
            'students_count' => $studentIds->count(),
            'lessons_count' => $lessons->count(),
            'average_lesson_completion' => $this->averageLessonCompletion($studentIds, $lessonIds),
            'exams_count' => $exams->count(),
            'attempts_count' => $attemptsCount,
            'scored_attempts_count' => $scoredCount,
            'pending_grading_count' => $attemptsQuery === null
                ? 0
                : (clone $attemptsQuery)->where('status', ExamAttemptStatus::Grading->value)->count(),
            'average_score' => $averageScore !== null ? (int) round($averageScore) : null,
            'flagged_integrity_count' => $attemptsQuery === null
                ? 0
                : (clone $attemptsQuery)
                    ->whereIn('integrity_status', [
                        IntegrityStatus::Flagged->value,
                        IntegrityStatus::Monitoring->value,
                    ])
                    ->count(),
            'top_performers' => $this->topPerformers($attemptsQuery),
            'weak_areas' => $this->weakAreas($exams, $attemptsQuery),
        ];
    }

    /**
     * Five highest-scoring students in the course.
     *
     * Grouped and limited in SQL, so a course of a thousand students does not
     * hydrate a thousand attempts to pick five names.
     *
     * @param  Builder<ExamAttempt>|null  $attemptsQuery
     * @return Collection<int, array<string, mixed>>
     */
    private function topPerformers(?Builder $attemptsQuery): Collection
    {
        if ($attemptsQuery === null) {
            return collect();
        }

        $rows = (clone $attemptsQuery)
            ->withFinalScore()
            ->selectRaw('student_id, AVG(percentage) as average, COUNT(*) as attempts')
            ->groupBy('student_id')
            ->orderByDesc('average')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // Resolve display names only for the rows actually shown.
        $students = User::query()
            ->whereIn('id', $rows->pluck('student_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($students) {
            $student = $students->get((int) $row->student_id);

            return [
                'student_id' => (int) $row->student_id,
                'display_name' => $student?->publicDisplayName() ?? 'Student',
                'average' => (int) round((float) $row->average),
                'attempts' => (int) $row->attempts,
            ];
        })->values();
    }

    /**
     * The five exams the cohort performs worst on.
     *
     * @param  Collection<int, Exam>  $exams
     * @param  Builder<ExamAttempt>|null  $attemptsQuery
     * @return Collection<int, array<string, mixed>>
     */
    private function weakAreas(Collection $exams, ?Builder $attemptsQuery): Collection
    {
        if ($attemptsQuery === null) {
            return collect();
        }

        $perExam = (clone $attemptsQuery)
            ->withFinalScore()
            ->selectRaw('exam_id, AVG(percentage) as average, COUNT(*) as attempts')
            ->groupBy('exam_id')
            ->get()
            ->keyBy('exam_id');

        return $exams
            ->map(function (Exam $exam) use ($perExam) {
                $row = $perExam->get($exam->getKey());

                return [
                    'exam_id' => $exam->getKey(),
                    'title' => $exam->title,
                    'attempts' => $row === null ? 0 : (int) $row->attempts,
                    'average' => $row === null ? null : (int) round((float) $row->average),
                ];
            })
            ->filter(fn (array $row) => $row['attempts'] > 0)
            ->sortBy('average')
            ->take(5)
            ->values();
    }

    /**
     * Mean lesson completion across the enrolled cohort.
     *
     * Each student's completion is rounded to a whole percentage before the
     * cohort mean is taken, matching how the figure has always been reported.
     *
     * @param  Collection<int, int>  $studentIds
     * @param  Collection<int, int>  $lessonIds
     */
    private function averageLessonCompletion(Collection $studentIds, Collection $lessonIds): int
    {
        $lessonCount = $lessonIds->count();
        $studentCount = $studentIds->count();

        if ($lessonCount === 0 || $studentCount === 0) {
            return 0;
        }

        // One grouped query instead of loading every progress row.
        $completedPerStudent = LessonProgress::query()
            ->whereIn('student_id', $studentIds)
            ->whereIn('lesson_id', $lessonIds)
            ->where('completed', true)
            ->selectRaw('student_id, COUNT(*) as completed')
            ->groupBy('student_id')
            ->pluck('completed', 'student_id');

        $sum = 0;
        foreach ($studentIds as $studentId) {
            $completed = (int) $completedPerStudent->get($studentId, 0);
            $sum += (int) round($completed / $lessonCount * 100);
        }

        return (int) round($sum / $studentCount);
    }
}
