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
        if ($account->isAnonymized()) {
            abort(409, 'This account has been anonymized and its email cannot be changed.');
        }

        $account->email = mb_strtolower($email);
        $account->save();

        return $account->fresh();
    }
}
