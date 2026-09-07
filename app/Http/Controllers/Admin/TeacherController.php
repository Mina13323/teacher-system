<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\CreateTeacherAction;
use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTeacherRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateTeacherRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only teacher account management. Routes are guarded by the admin
 * `before` gate and the `isAdmin()` authorize() checks in the requests.
 */
class TeacherController extends Controller
{
    public function __construct(
        private readonly CreateTeacherAction $createTeacher,
        private readonly UpdateUserAccountAction $updateAccount,
        private readonly UpdateAccountEmailAction $updateEmail,
        private readonly SetAccountActiveStateAction $setActiveState,
        private readonly ResetUserPasswordAction $resetPassword,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $teachers = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Teacher->value))
            ->with('roles')
            ->latest()
            ->paginate($this->perPage($request));

        return $this->success(UserResource::collection($teachers), 'Teachers retrieved.');
    }

    public function store(CreateTeacherRequest $request): JsonResponse
    {
        $teacher = $this->createTeacher->execute($request->validated());

        return $this->success(new UserResource($teacher->load('roles')), 'Teacher created.', 201);
    }

    public function show(Request $request, User $teacher): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(new UserResource($teacher->load('roles', 'courses')), 'Teacher retrieved.');
    }

    public function update(UpdateTeacherRequest $request, User $teacher): JsonResponse
    {
        if ($request->has('email')) {
            $this->updateEmail->execute($teacher, $request->string('email')->toString());
        }

        $account = $this->updateAccount->execute($teacher, $request->validated());

        return $this->success(new UserResource($account->load('roles')), 'Teacher updated.');
    }

    public function activate(Request $request, User $teacher): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(
            new UserResource($this->setActiveState->execute($teacher, true)->load('roles')),
            'Teacher activated.'
        );
    }

    public function deactivate(Request $request, User $teacher): JsonResponse
    {
        $this->authorizeAdmin($request);

        return $this->success(
            new UserResource($this->setActiveState->execute($teacher, false)->load('roles')),
            'Teacher deactivated.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request, User $teacher): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->resetPassword->execute($teacher, $request->string('password')->toString());

        return $this->success(null, 'Teacher password reset.');
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
