<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Phase 5.2 — Expiration is backend-authoritative and submission is idempotent.
 *
 *  - Requests that arrive after expires_at (save answer, submit, record
 *    integrity event) are rejected; the client cannot extend the attempt.
 *  - Repeated submit produces exactly one submission, one grading, one result.
 */
class ExpirationAndIdempotencyTest extends ApiTestCase
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

    public function test_save_answer_after_expiration_is_rejected(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->start($student, $exam);
        $attempt->expires_at = now()->subSecond();
        $attempt->save();

        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(422);

        $this->assertSame('expired', $attempt->fresh()->status->value);
        $this->assertDatabaseMissing('exam_answers', ['attempt_id' => $attempt->id]);
    }

    public function test_record_integrity_event_after_expiration_is_rejected(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->start($student, $exam);
        $attempt->expires_at = now()->subSecond();
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                'event_type' => 'TAB_SWITCH',
            ])->assertStatus(422);

        $this->assertDatabaseCount('exam_integrity_events', 0);
    }

    public function test_submit_at_exact_expiration_is_rejected(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->start($student, $exam);

        $attempt->expires_at = now()->subSecond();
        $attempt->save();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(422);

        $this->assertSame('expired', $attempt->fresh()->status->value);
    }

    public function test_client_cannot_extend_attempt_by_manipulating_expires_at(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->start($student, $exam);
        $attempt->save();

        // No student endpoint accepts expires_at, so a client cannot extend it.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['expires_at' => now()->addDay()->toISOString()])
            ->assertStatus(201);

        $this->assertSame(
            $attempt->started_at->copy()->addMinutes(1)->timestamp,
            $attempt->fresh()->expires_at->timestamp
        );
    }

    public function test_repeated_submit_is_idempotent_and_single_graded(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->start($student, $exam);
        $question = $exam->questions()->first();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(200);

        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);
        $second = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);
        $third = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->assertSame($first->json('data.percentage'), $second->json('data.percentage'));
        $this->assertSame($first->json('data.percentage'), $third->json('data.percentage'));

        // Exactly one grade persisted on the attempt and one submission.
        $fresh = $attempt->fresh();
        $this->assertSame(1, $fresh->score);
        $this->assertSame('submitted', $fresh->status->value);
        $this->assertNotNull($fresh->submitted_at);
    }
}
