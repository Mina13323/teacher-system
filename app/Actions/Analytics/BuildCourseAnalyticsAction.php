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
use Illuminate\Support\Collection;

/**
 * Per-course analytics for a teacher (or admin). Aggregates enrollment,
 * progress, performance and competition activity for a single course.
 *
 * @return array<string, mixed>
 */
class BuildCourseAnalyticsAction
{
    public function execute(User $user, Course $course): array
    {
        $enrollments = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with('student')
            ->get();

        $studentIds = $enrollments->pluck('student_id');

        $lessons = $course->lessons()->get();

        $progress = $studentIds->isEmpty()
            ? collect()
            : LessonProgress::query()
                ->whereIn('student_id', $studentIds)
                ->whereIn('lesson_id', $lessons->pluck('id'))
                ->get();

        $exams = Exam::query()->where('course_id', $course->getKey())->get();
        $examIds = $exams->pluck('id');

        $attempts = $examIds->isEmpty()
            ? collect()
            : ExamAttempt::query()->whereIn('exam_id', $examIds)->get();

        $submitted = $attempts->where('status', ExamAttemptStatus::Submitted);
        $avgPercentage = $submitted->whereNotNull('percentage')->avg('percentage');

        $flagged = $attempts->filter(fn (ExamAttempt $a) => in_array($a->integrity_status?->value, [
            IntegrityStatus::Flagged->value,
            IntegrityStatus::Monitoring->value,
        ], true));

        $topPerformers = $submitted
            ->whereNotNull('percentage')
            ->groupBy('student_id')
            ->map(fn (Collection $a) => [
                'student_id' => (int) $a->first()->student_id,
                'display_name' => $a->first()->student?->publicDisplayName() ?? 'Student',
                'average' => (int) round($a->whereNotNull('percentage')->avg('percentage')),
                'attempts' => $a->count(),
            ])
            ->sortByDesc('average')
            ->take(5)
            ->values();

        $weakAreas = $exams
            ->map(function (Exam $exam) use ($submitted) {
                $examSubmissions = $submitted->where('exam_id', $exam->getKey());

                return [
                    'exam_id' => $exam->getKey(),
                    'title' => $exam->title,
                    'attempts' => $examSubmissions->count(),
                    'average' => $examSubmissions->whereNotNull('percentage')->isEmpty()
                        ? null
                        : (int) round($examSubmissions->whereNotNull('percentage')->avg('percentage')),
                ];
            })
            ->filter(fn ($row) => $row['attempts'] > 0)
            ->sortBy('average')
            ->take(5)
            ->values();

        return [
            'course' => [
                'id' => $course->getKey(),
                'title' => $course->title,
                'status' => $course->status?->value,
            ],
            'enrollments_count' => $enrollments->count(),
            'students_count' => $studentIds->unique()->count(),
            'lessons_count' => $lessons->count(),
            'average_lesson_completion' => $this->averageLessonCompletion($progress, $lessons, $studentIds),
            'exams_count' => $exams->count(),
            'attempts_count' => $submitted->count(),
            'average_score' => $avgPercentage !== null ? (int) round($avgPercentage) : null,
            'flagged_integrity_count' => $flagged->count(),
            'top_performers' => $topPerformers,
            'weak_areas' => $weakAreas,
        ];
    }

    private function averageLessonCompletion(Collection $progress, Collection $lessons, Collection $studentIds): ?int
    {
        $lessonCount = $lessons->count();
        $studentCount = $studentIds->unique()->count();

        if ($lessonCount === 0 || $studentCount === 0) {
            return 0;
        }

        $sum = 0;
        foreach ($studentIds->unique() as $studentId) {
            $studentProgress = $progress->where('student_id', $studentId);
            $completed = $studentProgress->where('completed', true)->count();
            $sum += (int) round($completed / $lessonCount * 100);
        }

        return (int) round($sum / $studentCount);
    }
}
