<?php

namespace App\Actions\Auth;

use App\Exceptions\AccountDisabledException;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a user's credentials (email or student code) and returns the authenticated user.
 */
class LoginUserAction
{
    public function execute(string $identifier, string $password): User
    {
        $cleanIdentifier = trim($identifier);

        $user = User::query()
            ->where(function ($query) use ($cleanIdentifier) {
                $query->where('email', mb_strtolower($cleanIdentifier))
                    ->orWhere('student_code', mb_strtoupper($cleanIdentifier))
                    ->orWhere('student_code', $cleanIdentifier);
            })
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException();
        }

        if (! $user->isActive()) {
            throw new AccountDisabledException();
        }

        if ($user->isStudent() && ! $user->hasActiveAccess()) {
            throw new AccountDisabledException('Student account access is currently inactive or suspended.');
        }

        return $user;
    }
}
