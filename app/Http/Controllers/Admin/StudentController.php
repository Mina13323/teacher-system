<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Actions\Analytics\BuildStudentAnalyticsAction;
use App\Actions\Student\AnonymizeStudentAction;
use App\Actions\Student\ForceDeleteStudentAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnonymizeStudentRequest;
use App\Http\Requests\ForceDeleteStudentRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only oversight of any student account, beyond a single teacher's scope.
 * Routes are guarded by the admin `before` gate and `isAdmin()` checks.
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly UpdateUserAccountAction $updateAccount,
        private readonly UpdateAccountEmailAction $updateEmail,
        private readonly SetAccountActiveStateAction $setActiveState,
        private readonly ResetUserPasswordAction $resetPassword,
        private readonly BuildStudentAnalyticsAction $buildAnalytics,
        private readonly AnonymizeStudentAction $anonymizeStudent,
        private readonly ForceDeleteStudentAction $forceDeleteStudent,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Student->value))
            ->with('roles');

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
            } elseif ($status === 'inactive' || $status === 'suspended') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->string('academic_year')->toString());
        }

        $students = $query->latest()->paginate($this->perPage($request));

        return $this->success(StudentResource::collection($students), 'Students retrieved.');
    }

    public function show(Request $request, User $student): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(new StudentResource($student->load('roles', 'enrollments.course')), 'Student retrieved.');
    }

    public function update(UpdateStudentRequest $request, User $student): JsonResponse
    {
        if ($request->has('email')) {
            $this->updateEmail->execute($student, $request->string('email')->toString());
        }

        return $this->success(
            new StudentResource($this->updateAccount->execute($student, $request->validated())->load('roles')),
            'Student updated.'
        );
    }

    public function activate(Request $request, User $student): JsonResponse
    {
        $this->authorizeAdmin($request);

        $updatedStudent = $this->setActiveState->execute($student, true);
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute(
            'student.activate',
            $updatedStudent,
            ['student_id' => $updatedStudent->id, 'admin_action' => true],
            $request->user(),
            $request,
        );

        return $this->success(new StudentResource($updatedStudent->load('roles')), 'Student activated.');
    }

    public function deactivate(Request $request, User $student): JsonResponse
    {
        $this->authorizeAdmin($request);

        $updatedStudent = $this->setActiveState->execute($student, false);
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute(
            'student.deactivate',
            $updatedStudent,
            ['student_id' => $updatedStudent->id, 'admin_action' => true],
            $request->user(),
            $request,
        );

        return $this->success(new StudentResource($updatedStudent->load('roles')), 'Student deactivated; history retained.');
    }

    public function resetPassword(ResetPasswordRequest $request, User $student): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->resetPassword->execute($student, $request->string('password')->toString());

        return $this->success(null, 'Student password reset.');
    }

    public function analytics(Request $request, User $student): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(// Staff may see unpublished scores; the student's own endpoint does not.
            $this->buildAnalytics->execute($student, revealUnpublishedScores: true),
            'Student analytics retrieved.');
    }

    public function anonymize(AnonymizeStudentRequest $request, User $student): JsonResponse
    {
        $updatedStudent = $this->anonymizeStudent->execute($request->user(), $student);

        return $this->success(
            new StudentResource($updatedStudent),
            'Student account anonymized; enrollments and academic history retained.'
        );
    }

    public function forceDelete(ForceDeleteStudentRequest $request, User $student): JsonResponse
    {
        $this->forceDeleteStudent->execute(
            $request->user(),
            $student,
            $request->boolean('delete_academic_history'),
        );

        return $this->success(null, 'Student account permanently deleted.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Admin access required.');
    }

}
