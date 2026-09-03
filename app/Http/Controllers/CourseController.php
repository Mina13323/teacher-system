<?php

namespace App\Http\Controllers;

use App\Enums\CourseStatus;
use App\Http\Resources\CourseDetailResource;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public course browsing. Only published courses are exposed.
 */
class CourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = Course::query()
            ->published()
            ->with('creator')
            ->withCount(['units', 'lessons'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->success(CourseResource::collection($courses), 'Courses retrieved.');
    }

    public function show(Course $course): JsonResponse
    {
        abort_unless($course->status === CourseStatus::Published, 404, 'Course not found.');

        $course->load([
            'creator',
            'units' => fn ($q) => $q->orderBy('position'),
            'units.lessons' => fn ($q) => $q->where('is_published', true)->orderBy('position'),
            'units.lessons.videos' => fn ($q) => $q->where('is_published', true)->orderBy('position'),
        ])->loadCount(['units', 'lessons']);

        return $this->success(new CourseDetailResource($course), 'Course retrieved.');
    }
}
