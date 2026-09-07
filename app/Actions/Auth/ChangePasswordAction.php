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
    public function execute(User $user, string $currentPassword, string $newPassword): User
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new InvalidCredentialsException('The current password is incorrect.');
        }

        $user->password = $newPassword;
        $user->save();

        // Invalidate all of the user's other tokens so a leaked token is revoked.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return $user->fresh();
    }
}
