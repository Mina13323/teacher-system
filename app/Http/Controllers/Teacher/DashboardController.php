<?php

namespace App\Http\Controllers\Teacher;

use App\Enums\CourseStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        $query = Course::query()->withCount(['units', 'lessons', 'enrollments']);

        if (! $request->user()->isAdmin()) {
            $query->where('created_by', $request->user()->getKey());
        }

        $stats = $query->get();

        $totalEnrollments = $stats->sum('enrollments_count');
        $totalUnits = $stats->sum('units_count');
        $totalLessons = $stats->sum('lessons_count');

        $recent = Course::query()
            ->whereIn('id', $stats->pluck('id'))
            ->with('creator')
            ->withCount(['units', 'lessons', 'enrollments'])
            ->latest()
            ->limit(5)
            ->get();

        return $this->success([
            'courses_count' => $stats->count(),
            'published_count' => $stats->where('status', CourseStatus::Published)->count(),
            'draft_count' => $stats->where('status', CourseStatus::Draft)->count(),
            'total_enrollments' => $totalEnrollments,
            'total_units' => $totalUnits,
            'total_lessons' => $totalLessons,
            'recent_courses' => CourseResource::collection($recent),
        ], 'Dashboard retrieved.');
    }
}
