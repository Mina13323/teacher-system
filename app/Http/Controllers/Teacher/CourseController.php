<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Course\CreateCourseAction;
use App\Actions\Course\DeleteCourseAction;
use App\Actions\Course\UpdateCourseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher/admin course management. Business logic is delegated to Actions and
 * authorization is enforced by CoursePolicy via Form Requests / authorize().
 */
class CourseController extends Controller
{
    public function __construct(
        private readonly CreateCourseAction $createCourse,
        private readonly UpdateCourseAction $updateCourse,
        private readonly DeleteCourseAction $deleteCourse,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        $query = Course::query()->with('creator')->withCount('units');

        // Teachers only see the courses they own; admins see everything.
        if (! $request->user()->isAdmin()) {
            $query->where('created_by', $request->user()->getKey());
        }

        return $this->success(
            CourseResource::collection($query->latest()->paginate($request->integer('per_page', 15))),
            'Courses retrieved.'
        );
    }

    public function store(CreateCourseRequest $request): JsonResponse
    {
        $course = $this->createCourse->execute($request->user(), $request->validated());

        return $this->success(new CourseResource($course), 'Course created.', 201);
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $course->load(['creator', 'units.lessons.videos']);

        return $this->success(new CourseResource($course), 'Course retrieved.');
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $course = $this->updateCourse->execute($course, $request->validated());

        return $this->success(new CourseResource($course->load('creator')), 'Course updated.');
    }

    public function destroy(Course $course): JsonResponse
    {
        $this->authorize('delete', $course);

        $this->deleteCourse->execute($course);

        return $this->success(null, 'Course deleted.');
    }
}
