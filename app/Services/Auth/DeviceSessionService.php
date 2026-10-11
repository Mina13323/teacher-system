<?php

namespace App\Services\Auth;

use App\Actions\Auth\LoginUserAction;
use App\Exceptions\SessionTransferExpiredException;
use App\Exceptions\SessionTransferRequiredException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sign-ins per device, on top of Sanctum personal access tokens.
 *
 * Each sign-in is one token row: the browser holds a random secret and the
 * server stores only its hash, so the row is already a server-side session
 * record (label, created_at, last_used_at) and deleting it ends that sign-in
 * on the next request. No second session mechanism is added.
 *
 * Single active device (config auth_sessions.single_device_roles):
 *  - A correct password while another sign-in exists issues no token and
 *    revokes nothing. It returns a one-time transfer token, valid for a few
 *    minutes, that only proves the password was just checked.
 *  - Confirming with it revokes every other sign-in and issues the new one,
 *    under a lock on the user row, so concurrent confirmations (or a
 *    confirmation racing a sign-in) cannot leave two devices signed in, and
 *    a transfer token works once.
 *  - The lost-device recovery path is the same confirmation: the old device
 *    does not have to approve. Staff revocation (password reset,
 *    suspension, deactivation) is unchanged.
 *
 * Exam attempts are not touched: they belong to the account, so the new
 * device resumes the same attempt with its deadline and saved answers, and
 * the revoked device's next request fails authentication before any write.
 */
class DeviceSessionService
{
    public const ENDED_TRANSFERRED = 'session_transferred';

    public function __construct(private readonly LoginUserAction $login)
    {
    }

    public function appliesTo(User $user): bool
    {
        $roles = (array) config('auth_sessions.single_device_roles', []);

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    /**
     * Signs the (already authenticated) user in on this device and returns
     * the plain-text token, or throws SessionTransferRequiredException when
     * the account is in use on another device.
     */
    public function start(User $user, string $deviceLabel): string
    {
        if (! $this->appliesTo($user)) {
            return $user->createToken($deviceLabel)->plainTextToken;
        }

        $result = DB::transaction(function () use ($user, $deviceLabel) {
            $this->lockUser($user);
            $other = $this->latestLiveSession($user);

            if ($other === null) {
                return ['token' => $user->createToken($deviceLabel)->plainTextToken];
            }

            return ['other' => $other];
        });

        if (isset($result['token'])) {
            return $result['token'];
        }

        $ticket = Str::random(64);
        $ttl = (int) config('auth_sessions.transfer_ttl_seconds', 300);
        Cache::put($this->ticketKey($ticket), $user->getKey(), $ttl);

        $other = $result['other'];

        throw new SessionTransferRequiredException($ticket, $ttl, [
            'device' => $other->name,
            'last_active_at' => ($other->last_used_at ?? $other->created_at)?->toISOString(),
        ]);
    }

    /**
     * Confirms a move to this device: revokes every other sign-in of the
     * account and returns the new one.
     *
     * @return array{user: User, token: string, revoked: int}
     */
    public function transfer(string $transferToken, string $deviceLabel): array
    {
        $key = $this->ticketKey($transferToken);
        $userId = Cache::get($key);
        $user = $userId !== null ? User::find($userId) : null;

        if ($user === null) {
            throw new SessionTransferExpiredException();
        }

        // The account may have been suspended since its password was checked.
        $this->login->assertCanSignIn($user);

        [$token, $revoked] = DB::transaction(function () use ($user, $key, $deviceLabel) {
            $this->lockUser($user);

            // One use: a second confirmation of the same token, concurrent or
            // not, waits for the lock above and then finds it gone.
            if (Cache::pull($key) === null) {
                throw new SessionTransferExpiredException();
            }

            $revoked = $this->revokeAll($user, self::ENDED_TRANSFERRED);

            return [$user->createToken($deviceLabel)->plainTextToken, $revoked];
        });

        return ['user' => $user, 'token' => $token, 'revoked' => $revoked];
    }

    /**
     * Why a bearer token no longer works, when it was revoked by a transfer
     * (for the 401 message only; authentication has already failed).
     */
    public function endedReason(?string $bearerToken): ?string
    {
        if (! is_string($bearerToken) || ! str_contains($bearerToken, '|')) {
            return null;
        }

        [, $secret] = explode('|', $bearerToken, 2);

        return Cache::get($this->endedKey(hash('sha256', $secret)));
    }

    /** Whether a token is still inside the configured idle window. */
    public static function withinIdleWindow(PersonalAccessToken $token): bool
    {
        $days = config('auth_sessions.idle_timeout_days');
        if ($days === null) {
            return true;
        }

        $lastSeen = $token->last_used_at ?? $token->created_at;

        return $lastSeen !== null && $lastSeen->gt(now()->subDays((int) $days));
    }

    private function revokeAll(User $user, string $reason): int
    {
        $tokens = $user->tokens()->get(['id', 'token']);
        $until = now()->addDays((int) config('auth_sessions.ended_notice_days', 30));

        foreach ($tokens as $token) {
            Cache::put($this->endedKey($token->token), $reason, $until);
        }

        return $user->tokens()->whereIn('id', $tokens->pluck('id'))->delete();
    }

    /**
     * The most recently used sign-in that still authenticates, or null. Ones
     * that no longer would (expired, or idle past the configured window) do
     * not count as another device and are removed.
     */
    private function latestLiveSession(User $user): ?PersonalAccessToken
    {
        [$live, $ended] = $user->tokens()
            ->get(['id', 'name', 'token', 'last_used_at', 'created_at', 'expires_at'])
            ->partition(fn (PersonalAccessToken $token) => ($token->expires_at === null || $token->expires_at->isFuture())
                && self::withinIdleWindow($token));

        if ($ended->isNotEmpty()) {
            $user->tokens()->whereIn('id', $ended->pluck('id'))->delete();
        }

        return $live->sortByDesc(fn (PersonalAccessToken $token) => ($token->last_used_at ?? $token->created_at)?->getTimestamp() ?? 0)
            ->first();
    }

    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->value('id');
    }

    private function ticketKey(string $ticket): string
    {
        return 'auth:transfer:'.hash('sha256', $ticket);
    }

    private function endedKey(string $tokenHash): string
    {
        return 'auth:ended:'.$tokenHash;
    }
}
