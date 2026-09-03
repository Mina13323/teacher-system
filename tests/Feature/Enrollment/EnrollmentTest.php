<?php

namespace Tests\Feature\Enrollment;

use App\Enums\UserRole;
use App\Models\Lesson;
use Tests\Feature\ApiTestCase;

class EnrollmentTest extends ApiTestCase
{
    private function publishedCourseWithLessons(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->published()->create(['unit_id' => $unit->id]);

        return [$course, $lesson];
    }

    public function test_student_can_enroll(): void
    {
        [$course] = $this->publishedCourseWithLessons();
        $student = $this->createUserWithRole(UserRole::Student);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll");

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.course_id', $course->id);

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
    }

    public function test_duplicate_enrollment_is_rejected(): void
    {
        [$course] = $this->publishedCourseWithLessons();
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    public function test_student_can_see_enrolled_courses(): void
    {
        [$course] = $this->publishedCourseWithLessons();
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $course->id);
    }

    public function test_student_cannot_access_another_students_enrollment(): void
    {
        [$course] = $this->publishedCourseWithLessons();
        $studentA = $this->createUserWithRole(UserRole::Student);
        $studentB = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($studentA, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($studentB, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_student_cannot_enroll_in_unpublished_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'draft']);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }
}
