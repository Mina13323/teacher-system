<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Enrollment\EnrollStudentToCourseAction;
use App\Actions\Enrollment\UnenrollStudentAction;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollStudentCourseRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher-authorized enrollment management for a course. A teacher can only
 * manage enrollments for courses they own (or admin).
 */
class CourseStudentController extends Controller
{
    public function __construct(
        private readonly EnrollStudentToCourseAction $enrollStudent,
        private readonly UnenrollStudentAction $unenrollStudent,
    ) {
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('manageEnrollments', $course);

        $enrollments = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with('student')
            ->latest('enrolled_at')
            ->paginate($this->perPage($request));

        return $this->success(EnrollmentResource::collection($enrollments), 'Enrollments retrieved.');
    }

    public function store(EnrollStudentCourseRequest $request, Course $course): JsonResponse
    {
        $student = \App\Models\User::findOrFail($request->integer('student_id'));

        $enrollment = $this->enrollStudent->execute($student, $course);

        return $this->success(
            new EnrollmentResource($enrollment->load('student', 'course')),
            'Student enrolled.',
            201
        );
    }

    public function destroy(Request $request, Course $course, \App\Models\User $student): JsonResponse
    {
        $this->authorize('manageEnrollments', $course);

        $enrollment = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('student_id', $student->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->firstOrFail();

        $unrolled = $this->unenrollStudent->execute($enrollment);

        return $this->success(new EnrollmentResource($unrolled->load('student', 'course')), 'Student unenrolled.');
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
