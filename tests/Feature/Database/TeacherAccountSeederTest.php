<?php

namespace Tests\Feature\Database;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\TeacherAccountSeeder;
use Tests\Feature\ApiTestCase;

/**
 * Covers the provisioned primary Teacher account.
 */
class TeacherAccountSeederTest extends ApiTestCase
{
    public function test_seeder_creates_the_teacher_account(): void
    {
        $this->seed(TeacherAccountSeeder::class);

        $teacher = User::query()->where('email', TeacherAccountSeeder::DEFAULT_EMAIL)->first();

        $this->assertNotNull($teacher, 'The teacher account was not created.');
        $this->assertSame(TeacherAccountSeeder::DEFAULT_NAME, $teacher->name);
        $this->assertTrue($teacher->is_active);
        $this->assertTrue($teacher->hasRole(UserRole::Teacher->value));
        $this->assertFalse($teacher->hasRole(UserRole::Admin->value));
    }

    public function test_seeded_teacher_can_login_with_the_provisioned_credentials(): void
    {
        $this->seed(TeacherAccountSeeder::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => TeacherAccountSeeder::DEFAULT_EMAIL,
            'password' => TeacherAccountSeeder::DEFAULT_PASSWORD,
        ])
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(TeacherAccountSeeder::class);
        $this->seed(TeacherAccountSeeder::class);

        $this->assertSame(
            1,
            User::query()->where('email', TeacherAccountSeeder::DEFAULT_EMAIL)->count(),
            'Re-running the seeder must not create duplicate accounts.'
        );

        $teacher = User::query()->where('email', TeacherAccountSeeder::DEFAULT_EMAIL)->first();
        $this->assertCount(1, $teacher->roles, 'Re-running the seeder must not duplicate the role.');
    }
}
