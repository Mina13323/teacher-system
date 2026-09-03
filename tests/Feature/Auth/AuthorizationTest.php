<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use Tests\Feature\ApiTestCase;

class AuthorizationTest extends ApiTestCase
{
    public function test_teacher_can_create_a_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/courses', ['title' => 'Introduction to Marketing']);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', 'Introduction to Marketing');

        $this->assertDatabaseHas('courses', [
            'title' => 'Introduction to Marketing',
            'created_by' => $teacher->id,
        ]);
    }

    public function test_student_cannot_create_a_course(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/teacher/courses', ['title' => 'Should Fail'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_teacher_can_update_their_own_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = Course::factory()->create(['created_by' => $teacher->id]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}", [
                'title' => 'Updated Marketing Course',
                'status' => 'published',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', 'Updated Marketing Course');

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => 'Updated Marketing Course',
            'status' => 'published',
        ]);
    }

    public function test_teacher_cannot_update_another_teachers_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $course = Course::factory()->create(['created_by' => $otherTeacher->id]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}", ['title' => 'Hijacked'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => $course->title,
        ]);
    }

    public function test_guest_cannot_access_teacher_routes(): void
    {
        $this->getJson('/api/v1/teacher/courses')
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    private function createUserWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
