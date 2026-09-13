<?php

namespace Tests\Feature;

use App\Enums\UserRole;

class LookupAuditAndSafetyTest extends ApiTestCase
{
    public function test_missing_user_reference_returns_404(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students/999999')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_missing_student_returns_404(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/students/888888')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_missing_course_returns_404(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/courses/999999')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_missing_lesson_returns_404(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/lessons/999999/videos')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_missing_exam_returns_404(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/exams/999999')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_missing_competition_returns_404(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/competitions/999999')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }

    public function test_invalid_string_route_binding_returns_404(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        // Tests previously encountered runtime issue /undefined
        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students/undefined')
            ->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Resource not found.',
            ]);
    }
}
