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

        $allProgress = LessonProgress::query()
            ->where('student_id', $userId)
            ->whereHas('lesson.unit', fn ($q) => $q->whereIn('course_id', $enrollments->pluck('course_id')))
            ->with(['lesson' => fn ($q) => $q->with('videos')])
            ->orderByDesc('updated_at')
            ->get();

        return $this->success([
            'enrolled_courses_count' => $courses->count(),
            'completed_lessons_count' => $allProgress->where('completed', true)->count(),
            'in_progress_lessons_count' => $allProgress
                ->where('completed', false)
                ->where('progress_percentage', '>', 0)
                ->count(),
            'recently_accessed_lessons' => LessonProgressResource::collection($allProgress->take(5)),
            'courses' => StudentCourseResource::collection($courses),
        ], 'Dashboard retrieved.');
    }
}
