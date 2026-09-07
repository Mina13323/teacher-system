<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Activates or deactivates an account. Deactivation revokes the user's API
 * tokens so a deactivated (suspended) account cannot continue to authenticate
 * under an existing session; activation re-enables login.
 */
class SetAccountActiveStateAction
{
    public function execute(User $account, bool $active): User
    {
        $account->is_active = $active;
        $account->save();

        if (! $active) {
            $account->tokens()->delete();
        }

        return $account->fresh();
    }
}
