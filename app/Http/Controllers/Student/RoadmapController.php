<?php

namespace App\Http\Controllers\Student;

use App\Actions\Progress\BuildCourseRoadmapAction;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseRoadmapResource;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoadmapController extends Controller
{
    public function __construct(private readonly BuildCourseRoadmapAction $buildRoadmap)
    {
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        abort_unless($request->user()->isStudent(), 403, 'Student account required.');
        abort_unless(
            $request->user()->canAccessLessons() && $request->user()->hasActiveAccess(),
            403,
            'You do not have access to course lessons.'
        );

        $enrolled = Enrollment::query()
            ->where('student_id', $request->user()->getKey())
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();

        abort_unless($enrolled, 404, 'Course not found.');

        $course = $this->buildRoadmap->execute($course, $request->user());

        return $this->success(new CourseRoadmapResource($course), 'Roadmap retrieved.');
    }
}
