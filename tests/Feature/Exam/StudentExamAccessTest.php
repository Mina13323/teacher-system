<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class StudentExamAccessTest extends ApiTestCase
{
    use InteractsWithExams;

    private function enrolledStudentWithPublishedExam(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $course, $exam];
    }

    public function test_student_sees_only_enrolled_published_exams(): void
    {
        [$student, $course, $exam] = $this->enrolledStudentWithPublishedExam();

        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $otherCourse = $this->createCourse($otherTeacher, ['status' => 'published']);
        $this->makePublishedExam($otherTeacher, $otherCourse);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/exams')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $exam->id);
    }

    public function test_student_can_view_enrolled_published_exam(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $exam->id)
            ->assertJsonMissingPath('data.questions');
    }

    public function test_student_cannot_view_unpublished_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'draft']);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(404);
    }

    public function test_student_cannot_access_exam_in_unenrolled_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(403);
    }

    public function test_student_exam_detail_never_leaks_answer_key(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}");

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertArrayNotHasKey('is_correct', $json['data'] ?? []);
        $this->assertStringNotContainsString('is_correct', $response->getContent());
    }

    public function test_student_cannot_manage_exams(): void
    {
        [$student, $course, $exam] = $this->enrolledStudentWithPublishedExam();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", ['title' => 'X'])
            ->assertStatus(403);
    }
}
