<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development-only demonstration accounts.
 *
 * IMPORTANT: These credentials are intended for local / development use only
 * and must never be shipped to a production environment without change.
 */
class DeveloperUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->createUser('Admin', 'admin@example.com', UserRole::Admin);
        $this->createUser('Teacher', 'teacher@example.com', UserRole::Teacher);
        $this->createUser('Student', 'student@example.com', UserRole::Student);
    }

    private function createUser(string $name, string $email, UserRole $role): void
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );

        $user->assignRole($role->value);
    }
}
