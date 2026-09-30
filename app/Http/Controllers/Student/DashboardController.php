<?php

namespace App\Http\Controllers\Student;

use App\Actions\Progress\CalculateCourseProgressAction;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LessonProgressResource;
use App\Http\Resources\StudentCourseResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly CalculateCourseProgressAction $calculateProgress)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();

        $enrollments = Enrollment::query()
            ->where('student_id', $userId)
            ->where('status', EnrollmentStatus::Active->value)
            ->with(['course' => fn ($q) => $q->withCount([
                'units',
                'lessons' => fn ($q) => $q->where('is_published', true),
            ])])
            ->latest('enrolled_at')
            ->get();

        $courses = $enrollments->map(fn (Enrollment $enrollment) => $enrollment->course)->filter();
        $courseProgress = $this->calculateProgress->forCourses($courses, $request->user());

        $courses->each(function (Course $course) use ($courseProgress) {
            $course->progress = $courseProgress[$course->getKey()]['percentage'] ?? 0;
        });

        // Scoped once, reused for every figure below.
        $courseIds = $enrollments->pluck('course_id');
        $progress = fn () => LessonProgress::query()
            ->where('student_id', $userId)
            ->whereHas('lesson.unit', fn ($q) => $q->whereIn('course_id', $courseIds));

        // The counts are aggregates and the recent list is capped at five, so
        // only those five rows ever hydrate their lesson and videos. This used
        // to load every progress row the student had ever touched, each with its
        // full video list, purely to count them.
        return $this->success([
            'enrolled_courses_count' => $courses->count(),
            'completed_lessons_count' => $progress()->where('completed', true)->count(),
            'in_progress_lessons_count' => $progress()
                ->where('completed', false)
                ->where('progress_percentage', '>', 0)
                ->count(),
            'recently_accessed_lessons' => LessonProgressResource::collection(
                $progress()
                    ->with(['lesson' => fn ($q) => $q->with('videos')])
                    ->orderByDesc('updated_at')
                    ->limit(5)
                    ->get()
            ),
            'courses' => StudentCourseResource::collection($courses),

            // §36 — actionable tasks (not decoration): what to do next.
            'upcoming_exams' => \App\Models\Exam::query()
                ->whereIn('course_id', $courseIds)
                ->where('status', 'published')
                ->where(function ($q) {
                    $q->whereBetween('starts_at', [now(), now()->addDays(7)])
                        ->orWhere(fn ($w) => $w->whereNotNull('ends_at')->where('ends_at', '>', now())->whereNull('starts_at'));
                })
                ->orderBy('starts_at')
                ->limit(5)
                ->get(['id', 'title', 'course_id', 'starts_at', 'ends_at'])
                ->map(fn ($e) => [
                    'id' => $e->id, 'title' => $e->title, 'course_id' => $e->course_id,
                    'starts_at' => $e->starts_at?->toISOString(), 'ends_at' => $e->ends_at?->toISOString(),
                    'url' => "/student/exams/{$e->id}",
                ]),
            'pending_results' => \App\Models\ExamAttempt::query()
                ->where('student_id', $userId)
                ->whereIn('status', ['submitted', 'grading'])
                ->whereNull('grades_published_at')
                ->orderByDesc('submitted_at')
                ->limit(5)
                ->get(['id', 'exam_id', 'submitted_at', 'end_reason'])
                ->map(fn ($a) => [
                    'id' => $a->id, 'exam_id' => $a->exam_id,
                    'submitted_at' => $a->submitted_at?->toISOString(), 'end_reason' => $a->end_reason,
                    'url' => "/student/attempts/{$a->id}",
                ]),
            'recent_grades' => \App\Models\ExamAttempt::query()
                ->where('student_id', $userId)
                ->whereNotNull('grades_published_at')
                ->orderByDesc('grades_published_at')
                ->limit(5)
                ->with('exam:id,pass_percentage,title')
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'exam_id' => $a->exam_id,
                    'score' => $a->score, 'percentage' => $a->percentage,
                    'outcome' => $a->outcome()->value,
                    'published_at' => $a->grades_published_at?->toISOString(),
                    'url' => "/student/attempts/{$a->id}",
                ]),
            'assignments_due' => \App\Models\Assignment::query()
                ->whereIn('course_id', $courseIds)
                ->where('is_published', true)
                ->where('due_at', '>=', now()->subDays(7))
                ->whereDoesntHave('submissions', fn ($s) => $s->where('student_id', $userId))
                ->orderBy('due_at')
                ->limit(5)
                ->get(['id', 'title', 'course_id', 'due_at', 'points'])
                ->map(fn ($a) => [
                    'id' => $a->id, 'title' => $a->title, 'course_id' => $a->course_id,
                    'due_at' => $a->due_at?->toISOString(), 'points' => $a->points,
                    'url' => '/student/assignments',
                ]),
        ], 'Dashboard retrieved.');
    }
}
