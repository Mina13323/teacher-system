<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Creates a student account on behalf of a teacher (or admin) in a
 * teacher-owned LMS. The account is created with the supplied email and
 * password so the student can log in immediately.
 */
class CreateStudentAction
{
    public function execute(User $creator, array $data): User
    {
        Role::findOrCreate(UserRole::Student->value);

        $user = User::create([
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
            'bio' => $data['bio'] ?? null,
            'is_active' => true,
            'created_by' => $creator->getKey(),
            'profile_completed_at' => ($data['phone'] ?? null) !== null ? now() : null,
        ]);

        $user->assignRole(UserRole::Student->value);

        return $user;
    }
}
