<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Creates an assistant account on behalf of a teacher (or admin).
 *
 * An assistant is operational staff that works for the main Teacher. The
 * account is created with the supplied email/password so the assistant can log
 * in immediately, and it records who created the account for scoping.
 */
class CreateAssistantAction
{
    public function execute(User $creator, array $data): User
    {
        Role::findOrCreate(UserRole::Assistant->value);

        $user = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'bio' => $data['bio'] ?? null,
            'is_active' => true,
            'created_by' => $creator->getKey(),
        ]);

        $user->assignRole(UserRole::Assistant->value);

        return $user;
    }
}
