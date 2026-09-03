<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Registers a new account and assigns the default STUDENT role.
 */
class RegisterUserAction
{
    public function execute(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
        ]);

        // Ensure the role exists so registration is independent of seeding.
        Role::findOrCreate(UserRole::Student->value);

        $user->assignRole(UserRole::Student->value);

        return $user;
    }
}
