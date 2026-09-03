<?php

namespace Tests\Feature\Exam;

use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Option;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class QuestionManagementTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_teacher_can_add_question(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/questions", [
                'question_text' => 'What is 2 + 2?',
                'points' => 5,
            ])->assertStatus(201)
            ->assertJsonPath('data.type', QuestionType::SingleChoice->value)
            ->assertJsonPath('data.points', 5);
    }

    public function test_teacher_can_list_questions(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/questions")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_teacher_can_update_question(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $question = $exam->questions()->first();

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/questions/{$question->id}", ['points' => 10])
            ->assertStatus(200)
            ->assertJsonPath('data.points', 10);
    }

    public function test_teacher_can_add_option(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $question = $exam->questions()->first();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/options", [
                'option_text' => 'Another option',
            ])->assertStatus(201)
            ->assertJsonPath('data.is_correct', false);
    }

    public function test_teacher_can_update_option(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $question = $exam->questions()->first();
        $option = $question->options()->first();

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$option->id}", ['is_correct' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_correct', true);
    }

    public function test_teacher_can_delete_question(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $question = $exam->questions()->first();

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/questions/{$question->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    public function test_only_owning_teacher_can_modify_question(): void
    {
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['status' => 'published']);
        $exam = $this->makePublishedExam($owner, $course);
        $question = $exam->questions()->first();
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/teacher/questions/{$question->id}", ['points' => 99])
            ->assertStatus(403);
    }

    public function test_teacher_resource_exposes_answer_key(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/questions");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['options' => [['is_correct']]]]]);
    }
}
