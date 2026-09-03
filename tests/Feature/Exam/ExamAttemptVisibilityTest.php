<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class ExamAttemptVisibilityTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_teacher_can_list_attempts_for_own_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.student.name', $student->name)
            ->assertJsonPath('data.data.0.status', ExamAttemptStatus::Submitted->value);
    }

    public function test_teacher_can_view_attempt_detail(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.student.id', $student->id);
    }

    public function test_teacher_cannot_view_attempts_for_exam_they_do_not_own(): void
    {
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['status' => 'published']);
        $exam = $this->makePublishedExam($owner, $course);
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")
            ->assertStatus(403);
    }
}
