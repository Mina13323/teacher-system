<?php

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class CoTeachingCollaborationTest extends ApiTestCase
{
    use InteractsWithExams;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.co_teaching', true);
    }

    public function test_co_teachers_can_see_each_others_courses(): void
    {
        $teacherA = $this->createUserWithRole(UserRole::Teacher);
        $teacherB = $this->createUserWithRole(UserRole::Teacher);

        $courseA = $this->createCourse($teacherA, ['title' => 'Course by Teacher A']);
        $courseB = $this->createCourse($teacherB, ['title' => 'Course by Teacher B']);

        // Teacher B should see both courses
        $response = $this->actingAs($teacherB, 'sanctum')
            ->getJson('/api/v1/teacher/courses')
            ->assertStatus(200);

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertContains('Course by Teacher A', $titles);
        $this->assertContains('Course by Teacher B', $titles);
    }

    public function test_co_teacher_can_manage_and_update_another_teachers_course(): void
    {
        $teacherA = $this->createUserWithRole(UserRole::Teacher);
        $teacherB = $this->createUserWithRole(UserRole::Teacher);

        $course = $this->createCourse($teacherA, ['title' => 'Initial Title']);

        $this->actingAs($teacherB, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}", [
                'title' => 'Updated by Teacher B',
            ])->assertStatus(200);

        $this->assertDatabaseHas('courses', [
            'id' => $course->id,
            'title' => 'Updated by Teacher B',
        ]);
    }

    public function test_co_teacher_can_manage_another_teachers_exam(): void
    {
        $teacherA = $this->createUserWithRole(UserRole::Teacher);
        $teacherB = $this->createUserWithRole(UserRole::Teacher);

        $course = $this->createCourse($teacherA);
        $exam = $this->makeExam($teacherA, $course, ['title' => 'Midterm Exam']);

        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Midterm Exam');

        $this->actingAs($teacherB, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", [
                'title' => 'Midterm Exam - Revised by Teacher B',
            ])->assertStatus(200);

        $this->assertDatabaseHas('exams', [
            'id' => $exam->id,
            'title' => 'Midterm Exam - Revised by Teacher B',
        ]);
    }

    public function test_co_teacher_can_see_and_manage_all_students(): void
    {
        $teacherA = $this->createUserWithRole(UserRole::Teacher);
        $teacherB = $this->createUserWithRole(UserRole::Teacher);

        $student = $this->createUserWithRole(UserRole::Student, [
            'name' => 'Shared Student',
            'created_by' => $teacherA->id,
        ]);

        // Teacher B should be able to view and manage this student
        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Shared Student');

        $this->actingAs($teacherB, 'sanctum')
            ->patchJson("/api/v1/teacher/students/{$student->id}/deactivate")
            ->assertStatus(200);

        $this->assertFalse((bool) $student->fresh()->is_active);
    }

    public function test_co_teacher_can_enroll_student_in_partner_course(): void
    {
        $teacherA = $this->createUserWithRole(UserRole::Teacher);
        $teacherB = $this->createUserWithRole(UserRole::Teacher);

        $course = $this->createCourse($teacherA);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($teacherB, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/students", [
                'student_id' => $student->id,
            ])->assertStatus(201);

        $this->assertDatabaseHas('enrollments', [
            'course_id' => $course->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);
    }
}
