<?php

namespace App\Http\Controllers;

use App\Enums\CourseStatus;
use App\Http\Resources\CoursePreviewResource;
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
        // §44: published-catalog pages are cache-safe (60s TTL, flushed on any
        // course save). Academic state is never cached — only public metadata.
        $courses = app(\App\Services\CourseCatalogCache::class)->page(
            max(1, (int) $request->query('page', 1)),
            $this->perPage($request, 15),
        );

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

        return $this->success(new CoursePreviewResource($course), 'Course retrieved.');
    }
}
