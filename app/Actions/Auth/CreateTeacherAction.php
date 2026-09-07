<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Creates a teacher account (admin-only operation).
 */
class CreateTeacherAction
{
    public function execute(array $data): User
    {
        Role::findOrCreate(UserRole::Teacher->value);

        $user = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'bio' => $data['bio'] ?? null,
            'is_active' => true,
        ]);

        $user->assignRole(UserRole::Teacher->value);

        return $user;
    }
}
