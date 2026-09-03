<?php

namespace App\Actions\Auth;

use App\Exceptions\AccountDisabledException;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a user's credentials and returns the authenticated user.
 */
class LoginUserAction
{
    public function execute(string $email, string $password): User
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException();
        }

        if (! $user->isActive()) {
            throw new AccountDisabledException();
        }

        return $user;
    }
}
