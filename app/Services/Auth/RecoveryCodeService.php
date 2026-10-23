<?php

namespace App\Services\Auth;

use App\Actions\Auth\LoginUserAction;
use App\Exceptions\RecoveryFailedException;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-student recovery codes (password recovery without email or SMS).
 *
 *  1. The first time a student opens the app, issue() makes a code and returns
 *     it once; only a keyed hash is stored. The student confirms they saved it
 *     (confirm()), which is what makes it usable.
 *  2. A student who forgot the password gives the student code, the phone
 *     number on file and the recovery code (recover()). All three must match;
 *     otherwise they are told to contact the admin.
 *  3. A verified recovery signs the student in with an unknown placeholder
 *     password and must_change_password, so the only thing they can do is
 *     choose a new one. Completing that retires the code (see
 *     ChangePasswordAction) and a fresh one is shown on the next visit.
 */
class RecoveryCodeService
{
    /** No 0/O, 1/I/L: the code is read from paper or a screenshot. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LENGTH = 12;

    public function __construct(
        private readonly LoginUserAction $login,
        private readonly DeviceSessionService $sessions,
    ) {
    }

    /**
     * Makes a new code for a student who has not confirmed one yet and
     * returns it in the display form (XXXX-XXXX-XXXX). Asking again before
     * confirming simply replaces the unconfirmed code.
     */
    public function issue(User $user): string
    {
        abort_unless($user->needsRecoveryCode(), 409, 'Your recovery code is already saved.');

        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $user->forceFill(['recovery_code_hash' => $this->hash($code)])->save();

        return implode('-', str_split($code, 4));
    }

    /** The student confirmed they saved the code; from now on it works. */
    public function confirm(User $user): void
    {
        abort_if($user->recovery_code_hash === null, 409, 'No recovery code has been issued.');

        $user->forceFill(['recovery_code_confirmed_at' => now()])->save();
    }

    /**
     * Verifies student code + phone + recovery code and signs the student in
     * for the forced password change.
     *
     * @return array{user: User, token: string}
     *
     * @throws RecoveryFailedException
     */
    public function recover(string $studentCode, string $phone, string $code, string $deviceLabel): array
    {
        $studentCode = trim($studentCode);
        $user = User::query()
            ->where(fn ($query) => $query->where('student_code', mb_strtoupper($studentCode))
                ->orWhere('student_code', $studentCode))
            ->first();

        $normalizedPhone = PhoneNumber::normalize($phone);
        $storedHash = $user?->recovery_code_hash;

        // Always hash and compare, so an unknown account answers like a wrong code.
        $codeMatches = hash_equals($storedHash ?? str_repeat('0', 64), $this->hash($code));

        if ($user === null
            || ! $user->isStudent()
            || $user->isAnonymized()
            || $storedHash === null
            || $user->recovery_code_confirmed_at === null
            || $normalizedPhone === null
            || $user->phone === null
            || ! hash_equals($user->phone, $normalizedPhone)
            || ! $codeMatches
        ) {
            throw new RecoveryFailedException();
        }

        $this->login->assertCanSignIn($user);

        $token = DB::transaction(function () use ($user, $deviceLabel) {
            // Nobody knows this password; the student sets a real one next.
            $user->password = Str::random(48);
            $user->must_change_password = true;
            $user->forceFill(['recovery_verified_at' => now()])->save();

            // The old password is gone, so every earlier sign-in ends.
            $this->sessions->revokeAllFor($user);

            return $user->createToken($deviceLabel)->plainTextToken;
        });

        return ['user' => $user->fresh(), 'token' => $token];
    }

    /** Normalizes what a person typed (case, dashes, spaces) and keys the hash to the app. */
    private function hash(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return hash_hmac('sha256', $clean, (string) config('app.key'));
    }
}
