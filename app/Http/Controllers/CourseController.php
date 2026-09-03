<?php

namespace App\Http\Controllers;

use App\Enums\CourseStatus;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, unauthenticated course browsing. Only published courses are exposed.
 */
class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->published()
            ->with('creator')
            ->withCount('units')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(CourseResource::collection($courses), 'Courses retrieved.');
    }

    public function show(Course $course): JsonResponse
    {
        abort_unless($course->status === CourseStatus::Published, 404, 'Course not found.');

        $course->load(['creator', 'units.lessons.videos']);

        return $this->success(new CourseResource($course), 'Course retrieved.');
    }
}
