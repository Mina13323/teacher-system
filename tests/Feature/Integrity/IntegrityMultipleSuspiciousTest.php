<?php

namespace Tests\Feature\Integrity;

use App\Models\ExamIntegrityEvent;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

/**
 * Phase 4.1 hardening: the MULTIPLE_SUSPICIOUS_EVENTS condition must be a
 * server-derived state, never a client-submittable event, and must never cause
 * double-counting of the same evidence.
 */
class IntegrityMultipleSuspiciousTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_client_cannot_submit_synthetic_summary_event(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, ['event_type' => 'MULTIPLE_SUSPICIOUS_EVENTS'])
            ->assertStatus(422);

        $this->assertSame(0, ExamIntegrityEvent::where('attempt_id', $attempt->id)->count());
    }

    public function test_server_derives_suspicious_state_from_individual_events(): void
    {
        // enable the protections so all three events are risk-bearing
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => true, 'fullscreen_required' => true]
        );

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $this->postEvent($student, $attempt, ['event_type' => 'COPY_ATTEMPT']);
        $this->postEvent($student, $attempt, ['event_type' => 'FULLSCREEN_EXIT']);

        $attempt->refresh();

        // 2 + 2 + 2 = 6 -> FLAGGED.
        $this->assertSame(6, $attempt->risk_score);
        $this->assertSame('flagged', $attempt->integrity_status->value);

        // The server-derived condition is exposed to the teacher.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}/integrity")
            ->assertStatus(200)
            ->assertJsonPath('data.multiple_suspicious_events', true)
            ->assertJsonPath('data.risk_score', 6);
    }

    public function test_no_double_counting_of_same_evidence(): void
    {
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => true, 'fullscreen_required' => true]
        );

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $this->postEvent($student, $attempt, ['event_type' => 'COPY_ATTEMPT']);
        $this->postEvent($student, $attempt, ['event_type' => 'FULLSCREEN_EXIT']);

        $attempt->refresh();

        // risk is 2+2+2 = 6, NOT 6 + 3 (no synthetic summary event).
        $this->assertSame(6, $attempt->risk_score);

        // No MULTIPLE_SUSPICIOUS_EVENTS row is ever persisted.
        $this->assertSame(
            0,
            ExamIntegrityEvent::where('attempt_id', $attempt->id)
                ->where('event_type', 'MULTIPLE_SUSPICIOUS_EVENTS')
                ->count()
        );
    }

    public function test_single_distinct_risk_event_is_not_multiple_suspicious(): void
    {
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $attempt->refresh();

        $this->assertSame(2, $attempt->risk_score);
        $this->assertSame('normal', $attempt->integrity_status->value);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}/integrity")
            ->assertStatus(200)
            ->assertJsonPath('data.multiple_suspicious_events', false);
    }
}
