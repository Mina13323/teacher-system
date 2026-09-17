<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Provisions the primary Teacher account.
 *
 * Idempotent: safe to re-run. The account is looked up by email; its name,
 * password and role are (re)applied every run so the credentials below always
 * match the database.
 *
 * Credentials are supplied from the environment without editing this file:
 *
 *   TEACHER_ACCOUNT_NAME
 *   TEACHER_ACCOUNT_EMAIL
 *   TEACHER_ACCOUNT_PASSWORD
 *
 * SECURITY: no real credential is stored in this file. Outside local/testing a
 * password MUST come from TEACHER_ACCOUNT_PASSWORD; when it is absent a strong
 * random password is generated and written to the console once, so nothing
 * guessable ever reaches version control or a production database.
 */
class TeacherAccountSeeder extends Seeder
{
    /** Identity defaults, overridable via the TEACHER_ACCOUNT_* env vars. */
    public const DEFAULT_NAME = 'Maher El Masry';
    public const DEFAULT_EMAIL = 'maherelmasry@teacher.com';

    /**
     * Local/testing-only fallback. Deliberately not a real credential and never
     * used in production, where TEACHER_ACCOUNT_PASSWORD is required.
     */
    public const LOCAL_FALLBACK_PASSWORD = 'change-me-locally';

    public function run(): void
    {
        // `?:` rather than `env($k, $default)`: under `php artisan config:cache`
        // env() returns null for everything, and the fallbacks must still apply.
        $name = (string) (env('TEACHER_ACCOUNT_NAME') ?: self::DEFAULT_NAME);
        $email = strtolower((string) (env('TEACHER_ACCOUNT_EMAIL') ?: self::DEFAULT_EMAIL));
        $password = $this->resolvePassword();

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

    /**
     * Resolve the provisioning password.
     *
     * Order: TEACHER_ACCOUNT_PASSWORD from the environment, then a non-secret
     * local/testing fallback, then a freshly generated strong password that is
     * reported on the console exactly once. Production never falls back to
     * anything guessable.
     */
    protected function resolvePassword(): string
    {
        $configured = env('TEACHER_ACCOUNT_PASSWORD');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (app()->environment('local', 'testing')) {
            return self::LOCAL_FALLBACK_PASSWORD;
        }

        $generated = Str::password(20, letters: true, numbers: true, symbols: true);

        $this->command?->warn('TEACHER_ACCOUNT_PASSWORD was not set. A random password was generated for the teacher account. Save it now, it is shown only once: '.$generated);

        return $generated;
    }
}
