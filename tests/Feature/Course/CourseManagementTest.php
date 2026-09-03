<?php

namespace Tests\Feature\Course;

use App\Enums\UserRole;
use App\Models\Course;
use Tests\Feature\ApiTestCase;

class CourseManagementTest extends ApiTestCase
{
    public function test_teacher_can_create_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/courses', ['title' => 'Digital Marketing 101']);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', 'Digital Marketing 101');

        $this->assertDatabaseHas('courses', [
            'title' => 'Digital Marketing 101',
            'created_by' => $teacher->id,
        ]);
    }

    public function test_teacher_can_update_own_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}", ['title' => 'Updated Title'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Title');

        $this->assertDatabaseHas('courses', ['id' => $course->id, 'title' => 'Updated Title']);
    }

    public function test_teacher_cannot_update_another_teachers_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $other = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($other);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}", ['title' => 'Hijacked'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_student_cannot_create_course(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/teacher/courses', ['title' => 'Not Allowed'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_teacher_can_publish_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'draft']);

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/teacher/courses/{$course->id}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('courses', ['id' => $course->id, 'status' => 'published']);
    }

    public function test_admin_can_manage_any_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $admin = $this->createUserWithRole(UserRole::Admin);
        $course = $this->createCourse($teacher);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}", ['title' => 'Admin Edited'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Admin Edited');
    }

    public function test_unauthenticated_teacher_courses_require_authentication(): void
    {
        $this->getJson('/api/v1/teacher/courses')
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }
}
