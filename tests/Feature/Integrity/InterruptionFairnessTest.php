<?php

namespace Tests\Feature\Integrity;

use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityEventType;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P0.7/P0.8 — Interruption fairness: a browser/network event is NOT cheating.
 *
 * Policy under test (config/integrity.php + violation_warnings column):
 *   - connectivity loss / heartbeat timeout never flags, terminates, or logs;
 *   - each counted client-reported violation increments a warning counter;
 *   - the attempt is terminated ONLY when warnings exceed the attempt's frozen
 *     threshold (default 5 -> the 6th counted violation) with a single honest
 *     THRESHOLD_TERMINATION event and end_reason = integrity_threshold;
 *   - no fabricated WINDOW_BLUR (or any other) events on any path.
 */
class InterruptionFairnessTest extends ApiTestCase
{
    use InteractsWithExams;

    private function startedAttempt(array $examAttrs = []): ExamAttempt
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'show_result_immediately' => false,
        ], $examAttrs));

        $this->addSingleChoiceQuestion($exam, ['points' => 1]);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::findOrFail($startRes->json('data.id'));
        $attempt->setRelation('student', $student);

        return $attempt;
    }

    public function test_network_loss_and_heartbeat_timeout_create_no_integrity_state(): void
    {
        $attempt = $this->startedAttempt();

        // Simulate a long connectivity gap (phone call / killed tab / train tunnel).
        $attempt->last_heartbeat_at = now()->subSeconds(300);
        $attempt->save();

        // Returning to the attempt shows it untouched.
        $this->actingAs($attempt->student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'in_progress');

        $attempt->refresh();
        $this->assertSame('normal', $attempt->integrity_status->value);
        $this->assertSame(0, (int) $attempt->violation_warnings);
        $this->assertSame(0, $attempt->integrityEvents()->count());
    }

    public function test_single_violation_warns_but_does_not_terminate(): void
    {
        $attempt = $this->startedAttempt();

        $res = $this->actingAs($attempt->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                'event_type' => 'TAB_SWITCH',
                'metadata' => ['test' => 'true'],
            ])->assertStatus(200);

        $this->assertTrue($res->json('data.recorded'));
        $this->assertSame(1, $res->json('data.warning_count'));
        $this->assertSame(5, $res->json('data.warning_threshold'));
        $this->assertFalse($res->json('data.terminated'));

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->status, 'A single report must not end the attempt');
        $this->assertSame('normal', $attempt->integrity_status->value);
        $this->assertSame(1, (int) $attempt->violation_warnings);
    }

    public function test_terminates_only_after_frozen_threshold_exceeded(): void
    {
        $attempt = $this->startedAttempt();
        $student = $attempt->student;

        // Both detection defaults on; alternate types and advance the clock
        // past the dedup window so every violation is a distinct confirmation.
        $types = ['TAB_SWITCH', 'WINDOW_BLUR', 'TAB_SWITCH', 'WINDOW_BLUR', 'TAB_SWITCH', 'WINDOW_BLUR'];

        // The first 5 counted violations (index 0..4) must never terminate.
        foreach (array_slice($types, 0, 5) as $i => $type) {
            $this->travelTo(now()->addSeconds(10));

            $res = $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                    'event_type' => $type,
                    'metadata' => ['seq' => (string) $i],
                ])->assertStatus(200);

            $this->assertFalse($res->json('data.terminated'), "violation #".($i + 1)." must not terminate");
            $this->assertSame($i + 1, $res->json('data.warning_count'));
        }

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->status);
        $this->assertSame(5, (int) $attempt->violation_warnings);

        // The 6th counted violation exceeds threshold (> 5) and ends the attempt.
        $this->travelTo(now()->addSeconds(10));
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                'event_type' => 'TAB_SWITCH',
                'metadata' => ['seq' => '5'],
            ])->assertStatus(200);

        $this->assertTrue($res->json('data.terminated'));
        $this->assertSame(6, $res->json('data.warning_count'));

        $attempt->refresh();
        $this->assertNotSame(ExamAttemptStatus::InProgress, $attempt->status);
        $this->assertSame('flagged', $attempt->integrity_status->value);
        $this->assertSame('integrity_threshold', $attempt->end_reason);
        $this->assertNotNull($attempt->submitted_at);

        // ONE honest termination event — never a fabricated WINDOW_BLUR.
        $events = $attempt->integrityEvents()->get();
        $terminationEvents = $events->where('event_type', IntegrityEventType::ThresholdTermination->value);
        $this->assertCount(1, $terminationEvents);
        $this->assertSame(0, $events->where('event_type', IntegrityEventType::WindowBlur->value)->count());
        $this->assertSame(
            (int) config('integrity.THRESHOLD_TERMINATION.risk_points'),
            (int) $terminationEvents->first()->risk_points
        );

        // Termination was not a data loss: saved answers were graded server-side.
        $this->assertNotNull($attempt->fresh()->scored_at);
    }

    public function test_uncountable_events_never_produce_warnings_or_termination(): void
    {
        $attempt = $this->startedAttempt();

        // Zero-risk (WINDOW_FOCUS, FULLSCREEN_ENTER) and protection-disabled
        // (COPY_ATTEMPT etc.) events are preserved as evidence but are not
        // violations: default settings leave copy/paste/context-menu off, so
        // those attempts are allowed and cannot count against the student.
        foreach (['WINDOW_FOCUS', 'FULLSCREEN_ENTER', 'COPY_ATTEMPT', 'PASTE_ATTEMPT', 'CONTEXT_MENU_ATTEMPT'] as $type) {
            $this->travelTo(now()->addSeconds(10));

            $res = $this->actingAs($attempt->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                    'event_type' => $type,
                ])->assertStatus(200);

            $this->assertTrue($res->json('data.recorded'));
            $this->assertSame(0, $res->json('data.warning_count'));
            $this->assertFalse($res->json('data.terminated'));
        }

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->status);
        $this->assertSame(0, (int) $attempt->violation_warnings);
        // Informational events ARE recorded (audit trail) but carry zero weight.
        $this->assertSame(5, $attempt->integrityEvents()->count());
    }

    public function test_burst_dedup_does_not_inflate_warning_counter(): void
    {
        $attempt = $this->startedAttempt();

        // Three identical rapid-fire reports (same second) = one event.
        foreach (range(1, 3) as $i) {
            $this->actingAs($attempt->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                    'event_type' => 'TAB_SWITCH',
                    'metadata' => ['ts' => now()->toIso8601String(), 'burst' => (string) $i],
                ])->assertStatus(200)
                ->assertJsonPath('data.deduplicated', $i > 1);
        }

        $this->assertSame(1, (int) $attempt->fresh()->violation_warnings);
        $this->assertSame(1, $attempt->integrityEvents()->count());
    }

    public function test_warning_threshold_is_frozen_per_attempt(): void
    {
        $attempt = $this->startedAttempt();

        // The attempt froze NO explicit threshold at start (NULL -> config 5).
        // The teacher lowers the live exam threshold afterwards: the running
        // attempt must keep the rules it started with.
        $live = \App\Models\ExamIntegritySetting::query()
            ->where('exam_id', $attempt->exam_id)
            ->firstOrFail();
        $live->forceFill(['violation_warning_threshold' => 1, 'terminate_on_violation' => true])->save();

        $frozen = $attempt->integritySetting()->first();
        $this->assertNull($frozen?->violation_warning_threshold);

        // Two counted violations: exceeds the NEW live threshold (1) but the
        // attempt resolves its frozen NULL -> config default (5) -> no terminate.
        foreach (['TAB_SWITCH', 'WINDOW_BLUR'] as $i => $type) {
            $this->travelTo(now()->addSeconds(10));

            $res = $this->actingAs($attempt->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                    'event_type' => $type,
                ])->assertStatus(200);

            $this->assertFalse($res->json('data.terminated'));
            $this->assertSame(5, $res->json('data.warning_threshold'));
        }

        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->fresh()->status);
    }

    public function test_terminate_endpoint_below_threshold_records_nothing(): void
    {
        $attempt = $this->startedAttempt();

        $res = $this->actingAs($attempt->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/terminate", [
                'reason' => 'TAB_SWITCH',
            ])->assertStatus(200);

        $this->assertFalse($res->json('data.terminated'));
        $this->assertSame('in_progress', $res->json('data.status'));

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->status);
        $this->assertSame('normal', $attempt->integrity_status->value);
        $this->assertSame(0, $attempt->integrityEvents()->count(), 'A below-threshold terminate call must fabricate nothing');
    }

    public function test_detection_disabled_for_attempt_records_but_never_counts(): void
    {
        $attempt = $this->startedAttempt();

        // The frozen per-attempt settings disabled tab-switch detection after
        // start (teacher turned it off for accommodations, say). Reports are
        // preserved as zero-risk evidence but cannot warn or terminate.
        $attempt->integritySetting()->firstOrFail()
            ->forceFill(['detect_tab_switch' => false, 'terminate_on_violation' => true])->save();

        foreach (range(1, 7) as $i) {
            $this->travelTo(now()->addSeconds(10));

            $res = $this->actingAs($attempt->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                    'event_type' => 'TAB_SWITCH',
                ])->assertStatus(200);

            $this->assertTrue($res->json('data.recorded'));
            $this->assertSame(0, $res->json('data.warning_count'));
            $this->assertFalse($res->json('data.terminated'), 'Disabled detection must never terminate');
        }

        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->fresh()->status);
        $this->assertSame(0, (int) $attempt->fresh()->violation_warnings);
        $this->assertSame(7, $attempt->integrityEvents()->count());
    }

    public function test_server_only_event_types_are_rejected_from_client(): void
    {
        $attempt = $this->startedAttempt();

        foreach (['HEARTBEAT_TIMEOUT', 'THRESHOLD_TERMINATION', 'MULTIPLE_SUSPICIOUS_EVENTS'] as $type) {
            $this->actingAs($attempt->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                    'event_type' => $type,
                ])->assertStatus(422);
        }

        $this->assertSame(0, $attempt->integrityEvents()->count());
        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->fresh()->status);
    }

    public function test_legacy_attempt_without_frozen_threshold_resolves_from_config(): void
    {
        $attempt = $this->startedAttempt();

        // Legacy attempts (created before thresholds were frozen per attempt)
        // have NULL in the frozen settings row; the policy must resolve the
        // config default and terminate at exactly that boundary.
        $attempt->integritySetting()->firstOrFail()
            ->forceFill(['violation_warning_threshold' => null, 'terminate_on_violation' => true])->save();
        $attempt->forceFill(['violation_warnings' => 5])->save();

        $res = $this->actingAs($attempt->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                'event_type' => 'TAB_SWITCH',
            ])->assertStatus(200);

        $this->assertTrue($res->json('data.terminated'));
        $this->assertSame((int) config('integrity.warning_threshold'), $res->json('data.warning_threshold'));
    }

    public function test_recording_events_on_non_in_progress_attempt_is_rejected(): void
    {
        $attempt = $this->startedAttempt();
        $attempt->forceFill(['violation_warnings' => 5])->save();

        $this->actingAs($attempt->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/terminate", ['reason' => 'TAB_SWITCH'])
            ->assertStatus(200);

        $this->actingAs($attempt->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", [
                'event_type' => 'TAB_SWITCH',
            ])->assertStatus(422);

        // The termination itself left exactly one event (THRESHOLD_TERMINATION).
        $this->assertSame(1, $attempt->integrityEvents()->count());
    }
}
