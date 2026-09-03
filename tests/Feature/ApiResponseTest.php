<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;

class ApiResponseTest extends ApiTestCase
{
    public function test_validation_failure_returns_standard_envelope(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'not-an-email',
        ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed.',
            ])
            ->assertJsonStructure(['errors' => ['name', 'email', 'password']]);
    }

    public function test_authentication_failure_returns_standard_envelope(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_authorization_failure_returns_standard_envelope(): void
    {
        $student = User::factory()->create();
        $student->assignRole(UserRole::Student->value);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/teacher/courses', ['title' => 'Nope'])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_success_response_returns_standard_envelope(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole(UserRole::Teacher->value);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/courses', ['title' => 'A Great Course'])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Course created.',
            ])
            ->assertJsonStructure(['data' => ['id', 'title', 'slug', 'status']]);
    }
}
