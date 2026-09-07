<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Auth\CreateAssistantAction;
use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAssistantRequest;
use App\Http\Requests\UpdateAssistantRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assistant account management for a teacher-owned LMS.
 *
 * An assistant is operational staff created by the (single main) Teacher to
 * handle student operations. A teacher may only manage assistants they created;
 * an admin may manage any assistant. Assistants are NEVER granted independent
 * teacher powers (no content/exam/competition/analytics/integrity management).
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly CreateAssistantAction $createAssistant,
        private readonly UpdateUserAccountAction $updateAccount,
        private readonly UpdateAccountEmailAction $updateEmail,
        private readonly SetAccountActiveStateAction $setActiveState,
        private readonly ResetUserPasswordAction $resetPassword,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Assistant->value))
            ->with('roles');

        if (! $request->user()->isAdmin()) {
            $teacherId = $request->user()->getKey();
            $query->where('created_by', $teacherId);
        }

        $assistants = $query->latest()->paginate($this->perPage($request));

        return $this->success(UserResource::collection($assistants), 'Assistants retrieved.');
    }

    public function store(CreateAssistantRequest $request): JsonResponse
    {
        $assistant = $this->createAssistant->execute($request->user(), $request->validated());

        return $this->success(
            new UserResource($assistant->load('roles')),
            'Assistant created.',
            201
        );
    }

    public function show(Request $request, User $assistant): JsonResponse
    {
        $this->authorizeManaged($request, $assistant);

        return $this->success(
            new UserResource($assistant->load('roles')),
            'Assistant retrieved.'
        );
    }

    public function update(UpdateAssistantRequest $request, User $assistant): JsonResponse
    {
        $this->authorizeManaged($request, $assistant);

        if ($request->has('email')) {
            $this->updateEmail->execute($assistant, $request->string('email')->toString());
        }

        $account = $this->updateAccount->execute($assistant, $request->validated());

        return $this->success(
            new UserResource($account->load('roles')),
            'Assistant updated.'
        );
    }

    public function activate(Request $request, User $assistant): JsonResponse
    {
        $this->authorizeManaged($request, $assistant);

        return $this->success(
            new UserResource($this->setActiveState->execute($assistant, true)->load('roles')),
            'Assistant activated.'
        );
    }

    public function deactivate(Request $request, User $assistant): JsonResponse
    {
        $this->authorizeManaged($request, $assistant);

        return $this->success(
            new UserResource($this->setActiveState->execute($assistant, false)->load('roles')),
            'Assistant deactivated.'
        );
    }

    public function resetPassword(Request $request, User $assistant): JsonResponse
    {
        $this->authorizeManaged($request, $assistant);

        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->resetPassword->execute($assistant, $request->string('password')->toString());

        return $this->success(null, 'Assistant password reset.');
    }

    /**
     * Any staff member (teacher/admin) may list/create assistants.
     */
    private function authorizeStaff(Request $request): void
    {
        abort_unless(
            $request->user()->isAdmin()
                || $request->user()->hasPermissionTo('assistants.view'),
            403,
            'Assistant management is not available.'
        );
    }

    /**
     * A user may manage a specific assistant only if they created it (or admin).
     */
    private function authorizeManaged(Request $request, User $assistant): void
    {
        abort_unless(
            $request->user()->isAdmin()
                || ((int) $assistant->created_by === (int) $request->user()->getKey()),
            403,
            'You do not manage this assistant.'
        );
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
