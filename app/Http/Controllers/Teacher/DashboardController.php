<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        // Staff scoping so an Assistant's dashboard reflects the Teacher's LMS.
        $ownerIds = $request->user()->staffOwnerIds();

        /** @return Builder<Course> */
        $courses = function () use ($ownerIds): Builder {
            $query = Course::query();

            if ($ownerIds !== null) {
                $query->whereIn('created_by', $ownerIds);
            }

            return $query;
        };

        $courseIds = $courses()->pluck('id');

        // Every figure is one aggregate query. This used to load every course the
        // teacher owns — with three withCount subqueries attached to each — and
        // then sum the results in PHP, purely to render five numbers.
        return $this->success([
            'courses_count' => $courses()->count(),
            'published_count' => $courses()->where('status', CourseStatus::Published->value)->count(),
            'draft_count' => $courses()->where('status', CourseStatus::Draft->value)->count(),
            'total_enrollments' => $courseIds->isEmpty()
                ? 0
                : Enrollment::query()->whereIn('course_id', $courseIds)->count(),
            'total_units' => $courseIds->isEmpty()
                ? 0
                : Unit::query()->whereIn('course_id', $courseIds)->count(),
            'total_lessons' => $courseIds->isEmpty()
                ? 0
                : Lesson::query()
                    ->whereHas('unit', fn ($q) => $q->whereIn('course_id', $courseIds))
                    ->count(),
            'recent_courses' => CourseResource::collection(
                $courses()
                    ->with('creator')
                    ->withCount(['units', 'lessons', 'enrollments'])
                    ->latest()
                    ->limit(5)
                    ->get()
            ),
        ], 'Dashboard retrieved.');
    }
}
