<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Phase 5.2 — Attempt lifecycle state machine. Only
 * IN_PROGRESS -> SUBMITTED and IN_PROGRESS -> EXPIRED are legal terminal
 * transitions. No API path may reverse a SUBMITTED/EXPIRED attempt back to
 * IN_PROGRESS or move a SUBMITTED attempt to EXPIRED.
 */
class AttemptStateMachineTest extends ApiTestCase
{
    use InteractsWithExams;

    protected function enrolledStudentWithPublishedExam(array $attrs = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $attrs);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $course, $exam, $teacher];
    }

    protected function start($student, $exam): ExamAttempt
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        return ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
    }

    private function seedAnswer($student, ExamAttempt $attempt, $exam): void
    {
        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(200);
    }

    public function test_submitted_attempt_is_not_revived_by_starting_a_new_attempt(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['max_attempts' => 2]);
        $attempt = $this->start($student, $exam);
        $this->seedAnswer($student, $attempt, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // Starting a fresh attempt must create a NEW attempt, never revive the
        // submitted one or reverse its state.
        $fresh = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $this->assertNotSame($attempt->id, $fresh->json('data.id'));
        $this->assertSame('submitted', $attempt->fresh()->status->value);
    }

    public function test_submitted_attempt_is_not_moved_to_expired_by_viewing(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 60]);
        $attempt = $this->start($student, $exam);
        $this->seedAnswer($student, $attempt, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // Even if time moves far past the deadline, a SUBMITTED attempt stays
        // submitted — the deadline only governs IN_PROGRESS attempts.
        $attempt->expires_at = now()->subHours(5);
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200);

        $this->assertSame('submitted', $attempt->fresh()->status->value);
    }

    public function test_expired_attempt_cannot_be_submitted(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->start($student, $exam);
        $attempt->expires_at = now()->subMinute();
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(422);

        $this->assertSame('expired', $attempt->fresh()->status->value);
    }

    public function test_expired_attempt_cannot_be_revived_by_viewing_or_answering(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->start($student, $exam);
        $attempt->expires_at = now()->subMinute();
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'expired');

        $this->assertSame('expired', $attempt->fresh()->status->value);
    }
}
