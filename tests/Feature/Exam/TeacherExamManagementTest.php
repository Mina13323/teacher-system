<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class TeacherExamManagementTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_teacher_can_create_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Midterm Exam',
                'duration_minutes' => 45,
                'pass_percentage' => 70,
                'max_attempts' => 2,
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Midterm Exam');

        $this->assertDatabaseHas('exams', [
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => 'draft',
        ]);
    }

    public function test_teacher_cannot_create_exam_for_course_they_do_not_own(): void
    {
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['status' => 'published']);
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Nope',
            ])->assertStatus(403);
    }

    public function test_teacher_can_list_exams_for_their_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $this->makeExam($teacher, $course, ['title' => 'Quiz 1']);
        $this->makeExam($teacher, $course, ['title' => 'Quiz 2']);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$course->id}/exams")
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data.data');
    }

    public function test_teacher_can_update_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", ['title' => 'Updated'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated');
    }

    public function test_teacher_can_view_exam_detail(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.questions');
    }

    public function test_publish_rejects_exam_without_questions(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_publish_rejects_question_without_correct_option(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        \App\Models\Question::factory()->create([
            'exam_id' => $exam->id,
            'position' => 1,
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_published_exam_can_be_archived(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_teacher_can_delete_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('exams', ['id' => $exam->id]);
    }

    public function test_teacher_cannot_manage_another_teachers_exam(): void
    {
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['status' => 'published']);
        $exam = $this->makeExam($owner, $course);
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", ['title' => 'Hack'])
            ->assertStatus(403);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(403);
    }

    public function test_student_cannot_manage_exams(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", ['title' => 'X'])
            ->assertStatus(403);
    }

    public function test_valid_exam_can_be_published(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');
    }
}
