<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Allows a teacher/admin to set a new password for a managed account (e.g. a
 * forgotten-password reset). Revokes the account's existing tokens so a
 * previously issued session cannot be used with the old password.
 */
class ResetUserPasswordAction
{
    public function execute(User $account, string $password): User
    {
        $account->password = $password;
        $account->save();

        $account->tokens()->delete();

        return $account->fresh();
    }
}
