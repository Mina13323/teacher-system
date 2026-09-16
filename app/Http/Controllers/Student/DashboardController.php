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
        ], 'Dashboard retrieved.');
    }
}
