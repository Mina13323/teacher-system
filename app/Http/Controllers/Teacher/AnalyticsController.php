<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Analytics\BuildCourseAnalyticsAction;
use App\Actions\Analytics\BuildStudentAnalyticsAction;
use App\Actions\Analytics\BuildTeacherOverviewAction;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher-facing analytics & reporting. Admin may access any resource; a teacher
 * sees only what belongs to their courses (own) or students they manage.
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly BuildTeacherOverviewAction $buildOverview,
        private readonly BuildCourseAnalyticsAction $buildCourseAnalytics,
        private readonly BuildStudentAnalyticsAction $buildStudentAnalytics,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        return $this->success($this->buildOverview->execute($request->user()), 'Overview analytics retrieved.');
    }

    public function courseAnalytics(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return $this->success($this->buildCourseAnalytics->execute($request->user(), $course), 'Course analytics retrieved.');
    }

    public function studentAnalytics(Request $request, User $student): JsonResponse
    {
        $this->authorize('view', $student);

        return $this->success($this->buildStudentAnalytics->execute($student), 'Student analytics retrieved.');
    }
}
