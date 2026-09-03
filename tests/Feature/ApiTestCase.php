<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base test case for feature/HTTP tests. Seeds roles + permissions and resets
 * the database between every test.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    protected function createUserWithRole(UserRole $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    protected function createCourse(User $creator, array $attributes = []): Course
    {
        return Course::factory()->create(array_merge(['created_by' => $creator->id], $attributes));
    }

    protected function createUnit(Course $course, array $attributes = []): Unit
    {
        $defaults = ['course_id' => $course->id];

        if (! array_key_exists('position', $attributes)) {
            $defaults['position'] = $course->units()->count() + 1;
        }

        return Unit::factory()->create(array_merge($defaults, $attributes));
    }
}
