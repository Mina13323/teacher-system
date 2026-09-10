<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Provisions the primary Teacher account.
 *
 * Idempotent: safe to re-run. The account is looked up by email; its name,
 * password and role are (re)applied every run so the credentials below always
 * match the database.
 *
 * Credentials may be overridden from the environment without editing this file:
 *
 *   TEACHER_ACCOUNT_NAME
 *   TEACHER_ACCOUNT_EMAIL
 *   TEACHER_ACCOUNT_PASSWORD
 *
 * SECURITY: the fallback password is a real credential committed to the repo.
 * For any non-local environment set TEACHER_ACCOUNT_PASSWORD in .env instead,
 * and rotate the default.
 */
class TeacherAccountSeeder extends Seeder
{
    /** Fallback credentials, overridable via the TEACHER_ACCOUNT_* env vars. */
    public const DEFAULT_NAME = 'Maher El Masry';
    public const DEFAULT_EMAIL = 'maherelmasry@teacher.com';
    public const DEFAULT_PASSWORD = 'Mr.Maher@systemforStudents';

    public function run(): void
    {
        // `?:` rather than `env($k, $default)`: under `php artisan config:cache`
        // env() returns null for everything, and the fallbacks must still apply.
        $name = (string) (env('TEACHER_ACCOUNT_NAME') ?: self::DEFAULT_NAME);
        $email = strtolower((string) (env('TEACHER_ACCOUNT_EMAIL') ?: self::DEFAULT_EMAIL));
        $password = (string) (env('TEACHER_ACCOUNT_PASSWORD') ?: self::DEFAULT_PASSWORD);

        // Roles must exist before assignment; RoleSeeder is normally run first,
        // but calling findOrCreate here keeps this seeder usable standalone.
        Role::findOrCreate(UserRole::Teacher->value);

        $teacher = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Sync rather than assign so a re-run cannot accumulate duplicate roles.
        $teacher->syncRoles([UserRole::Teacher->value]);
    }
}
