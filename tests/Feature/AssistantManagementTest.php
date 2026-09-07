<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ApiTestCase;

/**
 * Assistant role boundary. An assistant is operational staff under the main
 * teacher: they may create/manage student accounts and enrol them, but they
 * must never gain teacher powers (content, exams, competitions, analytics,
 * integrity, system management).
 */
class AssistantManagementTest extends ApiTestCase
{
    private function teacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function assistant(): User
    {
        return $this->createUserWithRole(UserRole::Assistant);
    }

    private function publishedCourse(User $teacher): Course
    {
        return Course::factory()->published()->create(['created_by' => $teacher->id]);
    }

    public function test_teacher_can_create_an_assistant(): void
    {
        $teacher = $this->teacher();

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/assistants', [
                'name' => 'Saad Assistant',
                'email' => 'assistant@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'phone' => '+201000000000',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'assistant@example.com');

        $this->assertDatabaseHas('users', ['email' => 'assistant@example.com', 'created_by' => $teacher->id]);
        $this->assertTrue(User::where('email', 'assistant@example.com')->first()->hasRole('assistant'));
    }

    public function test_assistant_can_create_a_student(): void
    {
        $assistant = $this->assistant();

        $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'New Student',
                'email' => 'student.assist@example.com',
                'password' => 'password123',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_assistant_can_list_students(): void
    {
        $assistant = $this->assistant();
        $this->createUserWithRole(UserRole::Student, ['name' => 'Some Student']);

        $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/v1/teacher/students')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_assistant_can_enroll_a_student_into_a_published_course(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $assistant = $this->assistant();

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/students", [
                'student_id' => $student->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_assistant_cannot_list_or_manage_courses(): void
    {
        $assistant = $this->assistant();

        $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/v1/teacher/courses')
            ->assertStatus(403);

        $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/courses', [
                'title' => 'Hacked Course',
            ])
            ->assertStatus(403);
    }

    public function test_assistant_cannot_access_analytics_or_exam_management(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);
        $assistant = $this->assistant();

        $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(403);

        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$course->id}/exams")
            ->assertStatus(403);

        $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/competitions', [
                'title' => 'Hacked',
                'exam_id' => 1,
            ])
            ->assertStatus(403);
    }

    public function test_admin_can_manage_any_assistant(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->teacher();
        $assistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $teacher->id]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/teacher/assistants/{$assistant->id}")
            ->assertStatus(200);
    }

    public function test_assistant_cannot_manage_another_teachers_assistant(): void
    {
        $teacherA = $this->teacher();
        $teacherB = $this->teacher();
        $assistantB = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $teacherB->id]);

        $this->actingAs($teacherA, 'sanctum')
            ->getJson("/api/v1/teacher/assistants/{$assistantB->id}")
            ->assertStatus(403);
    }
}
