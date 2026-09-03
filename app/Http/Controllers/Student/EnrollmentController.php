<?php

namespace App\Http\Controllers\Student;

use App\Actions\Enrollment\EnrollStudentAction;
use App\Actions\Progress\BuildCourseRoadmapAction;
use App\Actions\Progress\CalculateCourseProgressAction;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollCourseRequest;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\StudentCourseResource;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollStudentAction $enrollStudent,
        private readonly CalculateCourseProgressAction $calculateProgress,
        private readonly BuildCourseRoadmapAction $buildRoadmap,
    ) {
    }

    public function store(EnrollCourseRequest $request, Course $course): JsonResponse
    {
        $enrollment = $this->enrollStudent->execute($request->user(), $course);

        return $this->success(
            new EnrollmentResource($enrollment->load('course')),
            'Enrolled successfully.',
            201
        );
    }

    public function index(Request $request): JsonResponse
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $request->user()->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with(['course' => fn ($q) => $q->withCount([
                'units',
                'lessons' => fn ($q) => $q->where('is_published', true),
            ])])
            ->latest('enrolled_at')
            ->get();

        $courses = $enrollments->map(fn (Enrollment $enrollment) => $enrollment->course)->filter();

        $progress = $this->calculateProgress->forCourses($courses, $request->user());

        $courses->each(function (Course $course) use ($progress) {
            $course->progress = $progress[$course->getKey()]['percentage'] ?? 0;
        });

        return $this->success(StudentCourseResource::collection($courses), 'Courses retrieved.');
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $enrolled = Enrollment::query()
            ->where('student_id', $request->user()->getKey())
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();

        abort_unless($enrolled, 404, 'Course not found.');

        $course->loadCount([
            'units',
            'lessons' => fn ($q) => $q->where('is_published', true),
        ]);

        $course = $this->buildRoadmap->execute($course, $request->user());

        $course->progress = $course->roadmap_progress;

        return $this->success(new StudentCourseResource($course), 'Course retrieved.');
    }
}
