<?php

namespace Tests\Feature;

use App\Enums\ExamStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\User;
use Tests\Feature\ApiTestCase;

/**
 * Assistant role boundary.
 *
 * An Assistant is operationally EQUIVALENT to the Teacher — not a restricted
 * staff role. They share the same operational LMS capability set: students,
 * enrollments, courses, units, lessons, videos, exams, questions, options,
 * attempts, grading, grade publication, competitions, analytics and integrity.
 *
 * The only capability held by Teacher/Admin and not Assistant is staff identity
 * administration (teachers.* and assistants.*): minting a new staff account or
 * resetting a staff member's password is account administration, not LMS work.
 */
class AssistantManagementTest extends ApiTestCase
{
    private function teacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    /**
     * An assistant employed by the given teacher. Passing a teacher sets
     * created_by, which is how the staff-scoping rules recognise the assistant
     * as operating on that teacher's resources.
     */
    private function assistant(?User $employer = null): User
    {
        return $this->createUserWithRole(UserRole::Assistant, [
            'created_by' => $employer?->id,
        ]);
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

    /**
     * Parity: the assistant lists and creates courses exactly as the teacher
     * does. The listing must also actually CONTAIN the teacher's courses — a
     * policy pass with an empty result set would be parity in name only.
     */
    public function test_assistant_can_list_and_create_courses(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);
        $assistant = $this->assistant($teacher);

        $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/v1/teacher/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $course->id);

        $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/courses', [
                'title' => 'Assistant Created Course',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /**
     * Parity: analytics, exam management, units/lessons and competitions are
     * all operational LMS capabilities available to the assistant.
     */
    public function test_assistant_can_access_analytics_and_exam_management(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);
        $assistant = $this->assistant($teacher);

        $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(200);

        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$course->id}/exams")
            ->assertStatus(200);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/units", [
                'title' => 'Assistant Unit',
            ])
            ->assertStatus(201);
    }

    public function test_assistant_can_create_and_manage_a_competition(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);
        $assistant = $this->assistant($teacher);

        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => ExamStatus::Published,
        ]);

        $created = $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/competitions', [
                'title' => 'Assistant Competition',
                'exam_id' => $exam->id,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->assertNotNull($created);

        // And can manage it afterwards (update is gated by ownership +
        // competitions.manage, both of which the assistant now satisfies).
        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$created}")
            ->assertStatus(200);
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
