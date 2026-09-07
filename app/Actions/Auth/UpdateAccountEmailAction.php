<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Updates a managed account's email. The unique-value check is performed in the
 * Form Request; this action persists the normalized email.
 */
class UpdateAccountEmailAction
{
    public function execute(User $account, string $email): User
    {
        $account->email = mb_strtolower($email);
        $account->save();

        return $account->fresh();
    }
}
