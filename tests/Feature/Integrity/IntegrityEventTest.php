<?php

namespace Tests\Feature\Integrity;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\ExamIntegrityEvent;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

class IntegrityEventTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_student_can_report_valid_event(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH'])
            ->assertStatus(201)
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.deduplicated', false);

        $this->assertDatabaseHas('exam_integrity_events', [
            'attempt_id' => $attempt->id,
            'event_type' => 'TAB_SWITCH',
        ]);
    }

    public function test_invalid_event_type_is_rejected(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, ['event_type' => 'NOT_A_REAL_EVENT'])
            ->assertStatus(422);
    }

    public function test_event_for_another_students_attempt_is_rejected(): void
    {
        [$studentA, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();
        $studentB = $this->createUserWithRole(UserRole::Student);

        $this->postEvent($studentB, $attempt, ['event_type' => 'TAB_SWITCH'])
            ->assertStatus(403);
    }

    public function test_expired_attempt_rejects_events(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt(['duration_minutes' => 1]);

        $attempt->expires_at = now()->subMinute();
        $attempt->save();

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH'])
            ->assertStatus(422);
    }

    public function test_submitted_attempt_rejects_events(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH'])
            ->assertStatus(422);
    }

    public function test_client_cannot_set_risk_points_or_severity(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, [
            'event_type' => 'TAB_SWITCH',
            'risk_points' => 999,
            'severity' => 'high',
        ])->assertStatus(201);

        $event = ExamIntegrityEvent::where('attempt_id', $attempt->id)->firstOrFail();

        // Server computed values, not the client-supplied ones.
        $this->assertSame(2, $event->risk_points);
        $this->assertSame('medium', $event->severity->value);
        $this->assertNotSame(999, $event->risk_points);
    }

    public function test_client_cannot_set_integrity_status(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, [
            'event_type' => 'TAB_SWITCH',
            'integrity_status' => 'cleared',
        ])->assertStatus(201);

        // The attempt status is derived server-side, not from the payload.
        $this->assertSame('normal', $attempt->fresh()->integrity_status->value);
    }

    public function test_metadata_is_stored_but_limited(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, [
            'event_type' => 'TAB_SWITCH',
            'metadata' => ['visibility_state' => 'hidden'],
        ])->assertStatus(201);

        $event = ExamIntegrityEvent::where('attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame('hidden', $event->metadata['visibility_state']);
    }

    public function test_occurred_at_is_validated_and_rejects_backdating(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        // Long before the attempt started.
        $this->postEvent($student, $attempt, [
            'event_type' => 'TAB_SWITCH',
            'occurred_at' => $attempt->started_at->copy()->subHour()->toISOString(),
        ])->assertStatus(422);
    }
}
