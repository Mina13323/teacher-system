<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Allows a teacher/admin to set a new password for a managed account (e.g. a
 * forgotten-password reset). Requires the account to change the temporary
 * password and revokes existing tokens so old sessions cannot survive the reset.
 */
class ResetUserPasswordAction
{
    public function execute(User $account, string $password): User
    {
        if ($account->isAnonymized()) {
            abort(409, 'This account has been anonymized and its password cannot be reset.');
        }

        $account->password = $password;
        $account->must_change_password = true;
        $account->save();

        $account->tokens()->delete();

        return $account->fresh();
    }
}
