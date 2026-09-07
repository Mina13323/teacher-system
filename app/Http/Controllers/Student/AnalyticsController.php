<?php

namespace App\Http\Controllers\Student;

use App\Actions\Analytics\BuildStudentAnalyticsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's own analytics. Only scoped to the authenticated student; a student
 * can never query another student's analytics.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly BuildStudentAnalyticsAction $buildAnalytics)
    {
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($this->buildAnalytics->execute($request->user()), 'Your analytics retrieved.');
    }
}
