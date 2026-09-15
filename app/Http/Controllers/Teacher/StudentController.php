<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Auth\CreateStudentAction;
use App\Actions\Auth\RegenerateStudentCredentialsAction;
use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Actions\Enrollment\EnrollStudentToCourseAction;
use App\Actions\Student\AllowStudentImmediatelyAction;
use App\Actions\Student\RenewStudentAccessAction;
use App\Actions\Student\RestoreStudentAction;
use App\Actions\Student\SuspendStudentAction;
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
 * Student account management for a teacher-owned LMS. A teacher or authorized assistant
 * may manage students they created or students enrolled in courses they own.
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
        private readonly SuspendStudentAction $suspendStudent,
        private readonly RestoreStudentAction $restoreStudent,
        private readonly AllowStudentImmediatelyAction $allowStudentImmediately,
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

        if ($request->filled('academic_subject')) {
            $query->where('academic_subject', $request->string('academic_subject')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'suspended' || $status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('capability')) {
            $cap = $request->string('capability')->toString();
            if ($cap === 'lessons') {
                $query->where('can_access_lessons', true);
            } elseif ($cap === 'exams') {
                $query->where('can_take_exams', true);
            } elseif ($cap === 'competitions') {
                $query->where('can_join_competitions', true);
            }
        }

        $students = $query->latest()->paginate($this->perPage($request));

        return $this->success(StudentResource::collection($students), 'Students retrieved.');
    }

    public function store(CreateStudentRequest $request): JsonResponse
    {
        $student = $this->createStudent->execute($request->user(), $request->validated());

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

    public function suspend(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $reason = $request->input('reason') ?: $request->input('notes');
        $updatedStudent = $this->suspendStudent->execute($request->user(), $student, $reason);

        return $this->success(
            new StudentResource($updatedStudent),
            'Student suspended successfully.'
        );
    }

    public function restore(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $months = $request->integer('months', 1);
        $notes = $request->input('notes');

        $updatedStudent = $this->restoreStudent->execute($request->user(), $student, $months, $notes);

        return $this->success(
            new StudentResource($updatedStudent),
            'Student restored successfully.'
        );
    }

    public function allowImmediately(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $months = $request->integer('months', 1);
        $amount = $request->filled('amount') ? (float) $request->input('amount') : null;
        $notes = $request->input('notes');

        $updatedStudent = $this->allowStudentImmediately->execute(
            $request->user(),
            $student,
            $months,
            $amount,
            $notes
        );

        return $this->success(
            new StudentResource($updatedStudent),
            'Student access allowed immediately.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $this->resetPassword->execute($student, $request->string('password')->toString());

        return $this->success(null, 'Student password reset.');
    }

    public function resetCredentials(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $result = $this->regenerateCredentials->execute($student);

        return $this->success([
            'student' => new StudentResource($result['student']->load(['roles', 'latestAccessPeriod'])),
            'credentials' => $result['credentials'],
        ], 'Student credentials regenerated successfully.');
    }

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
