<?php

namespace Tests\Feature\Enrollment;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Tests\Feature\ApiTestCase;

/**
 * Teacher-driven enrollment management: a teacher enrolls/manages students in
 * their own courses, and cannot touch another teacher's course enrollments.
 */
class TeacherEnrollmentTest extends ApiTestCase
{
    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function makeStudent(): User
    {
        return $this->createUserWithRole(UserRole::Student);
    }

    public function test_teacher_can_enroll_a_student_in_their_own_course(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->makeStudent();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/students", [
                'student_id' => $student->id,
            ])->assertStatus(201);

        $this->assertDatabaseHas('enrollments', [
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);
    }

    public function test_teacher_cannot_enroll_a_student_in_another_teachers_course(): void
    {
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $course = $this->createCourse($teacherB); // owned by B
        $student = $this->makeStudent();

        $this->actingAs($teacherA, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/students", [
                'student_id' => $student->id,
            ])->assertStatus(403);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_teacher_can_unenroll_a_student_from_their_own_course(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->makeStudent();

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/courses/{$course->id}/students/{$student->id}")
            ->assertStatus(200);

        // Enrollment is preserved but cancelled (history not destroyed).
        $this->assertDatabaseHas('enrollments', [
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancelled_enrollment_stays_hidden_until_staff_reactivates_it(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->makeStudent();
        $enrolledAt = now()->subDays(30)->startOfSecond();
        $completedAt = now()->subDays(2)->startOfSecond();

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'cancelled',
            'enrolled_at' => $enrolledAt,
            'completed_at' => $completedAt,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.is_enrolled', false)
            ->assertJsonPath('data.enrollment_status', 'cancelled');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/courses')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(409);

        $this->assertDatabaseHas('enrollments', [
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => 'cancelled',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/students", [
                'student_id' => $student->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('enrollments', [
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => $enrolledAt->toDateTimeString(),
            'completed_at' => $completedAt->toDateTimeString(),
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/courses')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $course->id);
    }

    public function test_teacher_can_list_students_enrolled_in_their_course(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->makeStudent();

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$course->id}/students")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
