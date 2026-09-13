<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Auth\CreateStudentAction;
use App\Actions\Auth\RegenerateStudentCredentialsAction;
use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Actions\Enrollment\EnrollStudentToCourseAction;
use App\Actions\Student\RenewStudentAccessAction;
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
        private readonly RegenerateStudentCredentialsAction $regenerateCredentials,
        private readonly RenewStudentAccessAction $renewAccess,
        private readonly EnrollStudentToCourseAction $enrollStudent,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Student->value))
            ->with(['roles', 'latestAccessPeriod']);

        if (! $request->user()->isAdmin()) {
            $user = $request->user();
            $teacherId = $user->isTeacher()
                ? $user->getKey()
                : ($user->created_by ?: (User::role(UserRole::Teacher->value)->value('id') ?: $user->getKey()));

            $assistantIds = User::query()
                ->where('created_by', $teacherId)
                ->orWhereHas('roles', fn ($q) => $q->where('name', UserRole::Assistant->value))
                ->pluck('id');

            $enrolledInOwnCourses = Enrollment::query()
                ->whereIn('course_id', Course::query()->where('created_by', $teacherId)->pluck('id'))
                ->where('status', \App\Enums\EnrollmentStatus::Active->value)
                ->pluck('student_id');

            $query->where(fn ($q) => $q->where('created_by', $teacherId)
                ->orWhereIn('created_by', $assistantIds)
                ->orWhereIn('id', $enrolledInOwnCourses));
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->string('academic_year')->toString());
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive' || $status === 'suspended') {
                $query->where('is_active', false);
            }
        }

        $students = $query->latest()->paginate($this->perPage($request));

        return $this->success(StudentResource::collection($students), 'Students retrieved.');
    }

    public function store(CreateStudentRequest $request): JsonResponse
    {
        $student = $this->createStudent->execute($request->user(), $request->validated());

        // Optionally enroll into the courses selected.
        foreach ($request->validated('course_ids', []) as $courseId) {
            $course = Course::find($courseId);
            if ($course && ($request->user()->can('manageEnrollments', $course) || $request->user()->can('update', $course))) {
                $this->enrollStudent->execute($student, $course);
            }
        }

        $student->load(['roles', 'latestAccessPeriod']);

        $response = [
            'success' => true,
            'message' => 'Student created.',
            'data' => new StudentResource($student),
        ];

        if (! empty($student->generated_credentials)) {
            $response['credentials'] = $student->generated_credentials;
        }

        return response()->json($response, 201);
    }

    public function show(Request $request, User $student): JsonResponse
    {
        $this->authorize('view', $student);

        $student->load(['roles', 'enrollments.course', 'latestAccessPeriod', 'accessPeriods']);

        return $this->success(new StudentResource($student), 'Student retrieved.');
    }

    public function update(UpdateStudentRequest $request, User $student): JsonResponse
    {
        $this->authorize('update', $student);

        if ($request->has('email')) {
            $this->updateEmail->execute($student, $request->string('email')->toString());
        }

        $account = $this->updateAccount->execute($student, $request->validated());

        return $this->success(new StudentResource($account->load(['roles', 'latestAccessPeriod'])), 'Student updated.');
    }

    public function activate(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $student = $this->setActiveState->execute($student, true);

        return $this->success(new StudentResource($student->load(['roles', 'latestAccessPeriod'])), 'Student activated.');
    }

    public function deactivate(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $student = $this->setActiveState->execute($student, false);

        return $this->success(new StudentResource($student->load(['roles', 'latestAccessPeriod'])), 'Student deactivated.');
    }

    public function resetPassword(ResetPasswordRequest $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $this->resetPassword->execute($student, $request->string('password')->toString());

        return $this->success(null, 'Student password reset.');
    }

    /**
     * Regenerate credentials (student code + secure random password) with one-time reveal.
     */
    public function resetCredentials(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $result = $this->regenerateCredentials->execute($student);

        return $this->success([
            'student' => new StudentResource($result['student']->load(['roles', 'latestAccessPeriod'])),
            'credentials' => $result['credentials'],
        ], 'Student credentials regenerated successfully.');
    }

    /**
     * Staff renewal decision: Keep Active or Suspend Access.
     */
    public function renew(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:keep_active,suspend'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updatedStudent = $this->renewAccess->execute(
            $request->user(),
            $student,
            $validated['decision'],
            $validated['months'] ?? 1,
            isset($validated['amount']) ? (float) $validated['amount'] : null,
            $validated['notes'] ?? null,
        );

        $message = $validated['decision'] === 'keep_active'
            ? 'Student access kept active.'
            : 'Student access suspended.';

        return $this->success(
            new StudentResource($updatedStudent->load(['roles', 'latestAccessPeriod'])),
            $message
        );
    }

    public function destroy(Request $request, User $student): JsonResponse
    {
        $this->authorize('delete', $student);

        $student->tokens()->delete();
        $student->accessPeriods()->delete();
        $student->enrollments()->delete();
        $student->examAttempts()->delete();
        $student->lessonProgress()->delete();
        $student->delete();

        return $this->success(null, 'Student deleted successfully.');
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
