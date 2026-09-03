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
