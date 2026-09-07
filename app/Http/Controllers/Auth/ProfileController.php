<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\StudentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service profile management (any authenticated role). A user may update
 * their own public profile fields and change their own password; the email can
 * not be changed here (only by a manager) to avoid an account-takeover path.
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateProfileAction $updateProfile,
        private readonly ChangePasswordAction $changePassword,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->success(new StudentResource($request->user()->load('roles')), 'Profile retrieved.');
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->updateProfile->execute($request->user(), $request->validated());

        return $this->success(new StudentResource($user->load('roles')), 'Profile updated.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->changePassword->execute(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
        );

        return $this->success(null, 'Password changed.');
    }
}
