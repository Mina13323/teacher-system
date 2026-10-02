<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Enrollment\EnrollStudentToCourseAction;
use App\Actions\Enrollment\UnenrollStudentAction;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollStudentCourseRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
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

        $query = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->with('student');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->whereHas('student', function ($studentQuery) use ($search) {
                $studentQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $enrollments = $query
            ->latest('enrolled_at')
            ->paginate($this->perPage($request));

        return $this->success(EnrollmentResource::collection($enrollments), 'Enrollments retrieved.');
    }

    public function store(EnrollStudentCourseRequest $request, Course $course): JsonResponse
    {
        if ($request->filled('academic_year')) {
            $ownerId = $course->created_by;
            $staffIds = config('app.co_teaching', false)
                ? User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', [
                    UserRole::Teacher->value,
                    UserRole::Assistant->value,
                    UserRole::Admin->value,
                ]))->pluck('id')
                : User::query()->where('created_by', $ownerId)->orWhere('id', $ownerId)->pluck('id');

            $studentScope = User::role(UserRole::Student->value)
                ->where(function ($query) use ($staffIds) {
                    $query->whereIn('created_by', $staffIds);
                    if (config('app.co_teaching', false)) {
                        // Legacy unowned students are visible to all staff only
                        // in the explicitly configured co-teaching scope.
                        $query->orWhereNull('created_by');
                    }
                });

            $students = $studentScope
                ->where('academic_year', $request->string('academic_year')->toString())
                ->where('is_active', true)
                ->get();
            foreach ($students as $student) {
                $this->enrollStudent->execute($student, $course);
            }

            return $this->success([
                'academic_year' => $request->string('academic_year')->toString(),
                'enrolled_count' => $students->count(),
            ], 'Students enrolled.');
        }

        $student = User::findOrFail($request->integer('student_id'));
        abort_unless($student->isStudent(), 404, 'Student not found.');
        $this->authorize('enroll', $student);

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

}
