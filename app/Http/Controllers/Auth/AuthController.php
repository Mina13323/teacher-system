<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\DeviceSessionService;
use App\Services\Auth\RecoveryCodeService;
use App\Support\DeviceLabel;
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
        private readonly DeviceSessionService $sessions,
        private readonly RecoveryCodeService $recovery,
    ) {
    }

    /**
     * Forgotten password: the student code, the phone number on file and the
     * recovery code must all match. On success the student is signed in with
     * a session that can only choose a new password (must_change_password).
     * Anything else answers the same "contact the admin" failure.
     */
    public function recover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_code' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'recovery_code' => ['required', 'string', 'max:32'],
        ]);

        $device = $this->deviceLabel($request);
        $result = $this->recovery->recover(
            $validated['student_code'],
            $validated['phone'],
            $validated['recovery_code'],
            $device,
        );
        $user = $result['user'];

        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('auth.password_recovery', $user, [
            'device' => $device,
        ], $user);

        return $this->signedIn($user, $result['token'], 'Identity verified. Choose a new password.');
    }

    /**
     * Checks the password and signs this device in. For an account limited
     * to one device that is already signed in elsewhere, nothing is issued
     * or revoked: the response is 409 session_transfer_required with a
     * one-time transfer token (see transfer()).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->loginUser->execute(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        $device = $this->deviceLabel($request);
        $token = $this->sessions->start($user, $device);

        // §29 security event — actor is the authenticating user (not yet
        // session-authenticated here, so it is passed explicitly).
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('auth.login', $user, [
            'device' => $device,
        ], $user);

        return $this->signedIn($user, $token, 'Login successful.');
    }

    /**
     * Confirms signing in on this device and signs the account out of every
     * other device. Authorized by the transfer token from the login response
     * (the password was checked a moment ago); it works once.
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_token' => ['required', 'string', 'max:128'],
        ]);

        $device = $this->deviceLabel($request);
        $result = $this->sessions->transfer($validated['transfer_token'], $device);
        $user = $result['user'];

        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('auth.session_transfer', $user, [
            'device' => $device,
            'revoked_sessions' => $result['revoked'],
        ], $user);

        return $this->signedIn($user, $result['token'], 'Signed in on this device.');
    }

    private function signedIn(User $user, string $token, string $message): JsonResponse
    {
        return $this->success([
            'user' => new UserResource($user->loadMissing('roles')),
            'token' => $token,
            // The badge starts right after signing in, as it does from /auth/me.
            'unread_count' => $user->unreadNotificationCount(),
        ], $message);
    }

    /** An API client may name itself; the web app is labelled from its browser. */
    private function deviceLabel(Request $request): string
    {
        $named = trim((string) $request->input('device_name', ''));

        return $named !== '' ? mb_substr($named, 0, 60) : DeviceLabel::from($request->userAgent());
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
