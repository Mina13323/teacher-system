<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Course\CreateCourseAction;
use App\Actions\Course\DeleteCourseAction;
use App\Actions\Course\PublishCourseAction;
use App\Actions\Course\UnpublishCourseAction;
use App\Actions\Course\UpdateCourseAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Http\Resources\CourseDetailResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function __construct(
        private readonly CreateCourseAction $createCourse,
        private readonly UpdateCourseAction $updateCourse,
        private readonly DeleteCourseAction $deleteCourse,
        private readonly PublishCourseAction $publishCourse,
        private readonly UnpublishCourseAction $unpublishCourse,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        $query = Course::query()
            ->with('creator')
            ->withCount(['units', 'lessons', 'enrollments']);

        // Scope to the resources this staff member operates on, not merely the
        // ones they personally created: an Assistant works the Teacher's
        // courses and must see them, or the policy would authorize edits to
        // courses that never appear in the list.
        $ownerIds = $request->user()->staffOwnerIds();

        if ($ownerIds !== null) {
            $query->whereIn('created_by', $ownerIds);
        }

        return $this->success(
            CourseResource::collection($query->latest()->paginate($this->perPage($request, 15))),
            'Courses retrieved.'
        );
    }

    public function store(CreateCourseRequest $request): JsonResponse
    {
        $course = $this->createCourse->execute($request->user(), $request->validated());

        return $this->success(
            new CourseResource($course->loadCount(['units', 'lessons', 'enrollments'])),
            'Course created.',
            201
        );
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $course->load(['creator', 'units.lessons.videos'])
            ->loadCount(['units', 'lessons', 'enrollments']);

        return $this->success(new CourseDetailResource($course), 'Course retrieved.');
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $course = $this->updateCourse->execute($course, $request->validated());

        return $this->success(
            new CourseResource($course->load('creator')->loadCount(['units', 'lessons', 'enrollments'])),
            'Course updated.'
        );
    }

    public function publish(Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $course = $this->publishCourse->execute($course);

        return $this->success(new CourseResource($course), 'Course published.');
    }

    public function unpublish(Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $course = $this->unpublishCourse->execute($course);

        return $this->success(new CourseResource($course), 'Course unpublished.');
    }

    public function destroy(Course $course): JsonResponse
    {
        $this->authorize('delete', $course);

        $this->deleteCourse->execute($course);

        return $this->success(null, 'Course deleted.');
    }
}
