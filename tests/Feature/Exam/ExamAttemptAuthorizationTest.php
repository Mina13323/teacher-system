<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class ExamAttemptAuthorizationTest extends ApiTestCase
{
    use InteractsWithExams;

    /**
     * Create a published exam, a student enrolled in it, and start an attempt.
     */
    private function startedAttempt(): array
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

        $attempt = ExamAttempt::where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->firstOrFail();

        return [$student, $exam, $attempt];
    }

    public function test_student_cannot_view_another_students_attempt(): void
    {
        [$studentA, , $attempt] = $this->startedAttempt();
        $studentB = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($studentB, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(403);
    }

    public function test_student_cannot_answer_another_students_attempt(): void
    {
        [$studentA, $exam, $attempt] = $this->startedAttempt();
        $studentB = $this->createUserWithRole(UserRole::Student);
        $question = $exam->questions()->first();

        $this->actingAs($studentB, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $question->options()->first()->id,
            ])->assertStatus(403);
    }

    public function test_student_cannot_submit_another_students_attempt(): void
    {
        [$studentA, , $attempt] = $this->startedAttempt();
        $studentB = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($studentB, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(403);
    }

    public function test_student_cannot_view_exam_attempt_list_of_another_exam(): void
    {
        [$studentA, , $attempt] = $this->startedAttempt();
        $studentB = $this->createUserWithRole(UserRole::Student);

        // Student B is not enrolled in the exam's course, so it is inaccessible
        // even for the exam-level attempt history endpoint.
        $this->actingAs($studentB, 'sanctum')
            ->getJson("/api/v1/student/exams/{$attempt->exam_id}/attempts")
            ->assertStatus(403);
    }
}
