<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication for the Teacher-System SPA.
 *
 * Public self-registration is intentionally NOT exposed. Accounts are created
 * only by teachers/assistants (student operations) or admins through the
 * management portals, so the auth surface here is: login, me, logout.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly LoginUserAction $loginUser,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->loginUser->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        $token = $user->createToken($request->input('device_name', 'mobile'))->plainTextToken;

        return $this->success([
            'user' => new UserResource($user->load('roles')),
            'token' => $token,
        ], 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user()->load('roles')),
            'Authenticated user retrieved.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }
}
