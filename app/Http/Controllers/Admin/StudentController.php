<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Actions\Analytics\BuildStudentAnalyticsAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
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
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $students = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Student->value))
            ->with('roles')
            ->latest()
            ->paginate($this->perPage($request));

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

        return $this->success(
            new StudentResource($this->setActiveState->execute($student, true)->load('roles')),
            'Student activated.'
        );
    }

    public function deactivate(Request $request, User $student): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(
            new StudentResource($this->setActiveState->execute($student, false)->load('roles')),
            'Student deactivated.'
        );
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

        return $this->success($this->buildAnalytics->execute($student), 'Student analytics retrieved.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'Admin access required.');
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
