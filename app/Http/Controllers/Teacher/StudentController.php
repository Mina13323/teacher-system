<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Auth\CreateStudentAction;
use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Actions\Enrollment\EnrollStudentToCourseAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStudentRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student account management for a teacher-owned LMS. A teacher may only manage
 * students they created or students enrolled in courses they own (enforced by
 * StudentPolicy). Admins may manage any student.
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly CreateStudentAction $createStudent,
        private readonly UpdateUserAccountAction $updateAccount,
        private readonly UpdateAccountEmailAction $updateEmail,
        private readonly SetAccountActiveStateAction $setActiveState,
        private readonly ResetUserPasswordAction $resetPassword,
        private readonly EnrollStudentToCourseAction $enrollStudent,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Student->value))
            ->with('roles');

        if (! $request->user()->isAdmin() && ! $request->user()->isAssistant()) {
            $teacherId = $request->user()->getKey();
            $enrolledInOwnCourses = Enrollment::query()
                ->whereIn('course_id', Course::query()->where('created_by', $teacherId)->pluck('id'))
                ->where('status', \App\Enums\EnrollmentStatus::Active->value)
                ->pluck('student_id');
            $query->where(fn ($q) => $q->where('created_by', $teacherId)
                ->orWhereIn('id', $enrolledInOwnCourses));
        }

        $students = $query->latest()->paginate($this->perPage($request));

        return $this->success(StudentResource::collection($students), 'Students retrieved.');
    }

    public function store(CreateStudentRequest $request): JsonResponse
    {
        $student = $this->createStudent->execute($request->user(), $request->validated());

        // Optionally enroll into the courses the teacher selected.
        foreach ($request->validated('course_ids', []) as $courseId) {
            $course = Course::find($courseId);
            if ($course && $request->user()->can('update', $course)) {
                $this->enrollStudent->execute($student, $course);
            }
        }

        $student->load('roles');

        return $this->success(new StudentResource($student), 'Student created.', 201);
    }

    public function show(Request $request, User $student): JsonResponse
    {
        $this->authorize('view', $student);

        $student->load(['roles', 'enrollments.course']);

        return $this->success(new StudentResource($student), 'Student retrieved.');
    }

    public function update(UpdateStudentRequest $request, User $student): JsonResponse
    {
        $this->authorize('update', $student);

        if ($request->has('email')) {
            $this->updateEmail->execute($student, $request->string('email')->toString());
        }

        $account = $this->updateAccount->execute($student, $request->validated());

        return $this->success(new StudentResource($account->load('roles')), 'Student updated.');
    }

    public function activate(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $student = $this->setActiveState->execute($student, true);

        return $this->success(new StudentResource($student->load('roles')), 'Student activated.');
    }

    public function deactivate(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $student = $this->setActiveState->execute($student, false);

        return $this->success(new StudentResource($student->load('roles')), 'Student deactivated.');
    }

    public function resetPassword(ResetPasswordRequest $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $this->resetPassword->execute($student, $request->string('password')->toString());

        return $this->success(null, 'Student password reset.');
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
