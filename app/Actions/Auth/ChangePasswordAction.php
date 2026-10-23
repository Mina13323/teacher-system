<?php

namespace App\Actions\Auth;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Changes a user's own password after verifying the current password. The
 * current password is required so a stolen/leaked token cannot be used to
 * silently change the account password.
 */
class ChangePasswordAction
{
    public function execute(User $user, ?string $currentPassword, string $newPassword): User
    {
        // After a verified recovery the placeholder password is unknown to
        // everybody, so the recovery itself stands in for the current one.
        $recovering = $user->isRecoveringPassword();

        if (! $recovering && ! Hash::check((string) $currentPassword, $user->password)) {
            throw new InvalidCredentialsException('The current password is incorrect.');
        }

        $user->password = $newPassword;
        $user->must_change_password = false;
        if ($recovering) {
            // The code was used: the student is shown a new one next visit.
            $user->retireRecoveryCode();
        }
        $user->save();

        // Invalidate all of the user's other tokens so a leaked token is revoked.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return $user->fresh();
    }
}
