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
            ->assertJsonCount(1, 'data.data');
    }
}
