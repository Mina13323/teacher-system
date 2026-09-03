<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class ExamAttemptTest extends ApiTestCase
{
    use InteractsWithExams;

    private function enrolledStudentWithPublishedExam(array $examAttributes = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $examAttributes);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $course, $exam];
    }

    public function test_student_can_start_attempt(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start");

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.status', ExamAttemptStatus::InProgress->value)
            ->assertJsonCount(1, 'data.questions');

        $this->assertDatabaseHas('exam_attempts', [
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'in_progress',
        ]);
    }

    public function test_snapshot_is_frozen_at_start(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->first();

        $this->assertSame(1, $attempt->attemptQuestions()->count());
        $this->assertSame(2, $attempt->attemptQuestions()->first()->attemptOptions()->count());
    }

    public function test_existing_active_attempt_is_returned_not_duplicated(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start");

        $second = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start");

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->count());
    }

    public function test_attempt_limit_is_enforced(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['max_attempts' => 1]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_student_can_answer_with_correct_option(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('exam_answers', [
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'option_id' => $correct->id,
        ]);
    }

    public function test_cannot_answer_option_that_belongs_to_another_question(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        // Use an unrelated option.
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $otherCourse = $this->createCourse($otherTeacher, ['status' => 'published']);
        $otherExam = $this->makePublishedExam($otherTeacher, $otherCourse);
        $foreignOption = $otherExam->questions()->first()->options()->first();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $exam->questions()->first()->id,
                'option_id' => $foreignOption->id,
            ])->assertStatus(422);
    }

    public function test_submit_grades_and_is_idempotent(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['show_result_immediately' => true]);

        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $second = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->assertSame($first->json('data.attempt_id'), $second->json('data.attempt_id'));
        $this->assertSame(100, $first->json('data.percentage'));
        $this->assertTrue($first->json('data.passed'));
        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attempt->id,
            'status' => 'submitted',
            'percentage' => 100,
        ]);
    }

    public function test_immediate_result_can_be_suppressed(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['show_result_immediately' => false]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.attempt_id', $attempt->id)
            ->assertJsonMissing('data.score');
    }

    public function test_attempt_get_never_leaks_answer_key(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('is_correct', $response->getContent());
        $this->assertStringNotContainsString('correct_option', $response->getContent());
        $this->assertStringNotContainsString('answer_key', $response->getContent());
    }

    public function test_student_cannot_access_another_students_attempt(): void
    {
        [$studentA, , $exam] = $this->enrolledStudentWithPublishedExam();

        $this->actingAs($studentA, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $studentA->id)->where('exam_id', $exam->id)->firstOrFail();

        $studentB = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($studentB, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(403);
    }

    public function test_expired_attempt_cannot_be_answered(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        // Force the attempt to be past its deadline server-side.
        $attempt->expires_at = now()->subMinute();
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $exam->questions()->first()->id,
                'option_id' => $this->correctOption($exam->questions()->first())->id,
            ])->assertStatus(422);

        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attempt->id,
            'status' => 'expired',
        ]);
    }

    public function test_submit_on_expired_attempt_rejected(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        $attempt->expires_at = now()->subMinute();
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(422);
    }
}
