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

        // §29 security event — actor is the authenticating user (not yet
        // session-authenticated here, so it is passed explicitly).
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('auth.login', $user, [
            'device' => substr((string) $request->input('device_name', 'mobile'), 0, 60),
        ], $user);

        return $this->success([
            'user' => new UserResource($user->load('roles')),
            'token' => $token,
            // The badge starts right after signing in, as it does from /auth/me.
            'unread_count' => $user->unreadNotificationCount(),
        ], 'Login successful.');
    }

    /**
     * The signed-in user, plus their unread notification count so the
     * portal's badge is right from the first screen without a separate
     * request. The count is for the token's own user only, and is added here
     * rather than to UserResource so lists of users never count per row.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles');

        return $this->success(
            array_merge(
                (new UserResource($user))->resolve($request),
                ['unread_count' => $user->unreadNotificationCount()],
            ),
            'Authenticated user retrieved.'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('auth.logout', $request->user(), [], $request->user());

        return $this->success(null, 'Logged out successfully.');
    }
}
