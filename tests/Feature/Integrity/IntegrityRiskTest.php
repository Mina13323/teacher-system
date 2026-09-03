<?php

namespace Tests\Feature\Integrity;

use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

class IntegrityRiskTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_tab_switch_adds_expected_risk(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH'])
            ->assertStatus(201);

        $attempt->refresh();
        // detect_tab_switch defaults on -> +2. Below monitoring threshold (3).
        $this->assertSame(2, $attempt->risk_score);
        $this->assertSame('normal', $attempt->integrity_status->value);
    }

    public function test_copy_attempt_adds_expected_risk(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => true]
        );

        $this->postEvent($student, $attempt, ['event_type' => 'COPY_ATTEMPT'])
            ->assertStatus(201);

        $attempt->refresh();
        $this->assertSame(2, $attempt->risk_score);
    }

    public function test_disabled_event_does_not_increase_risk(): void
    {
        // prevent_copy is off by default, so a copy attempt is recorded but
        // ignored (0 risk).
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->postEvent($student, $attempt, ['event_type' => 'COPY_ATTEMPT'])
            ->assertStatus(201);

        $attempt->refresh();
        $this->assertSame(0, $attempt->risk_score);
        $this->assertSame('normal', $attempt->integrity_status->value);
    }

    public function test_multiple_events_produce_deterministic_total(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => true, 'prevent_paste' => true]
        );

        // TAB_SWITCH(+2) + COPY(+2) + PASTE(+2) = 6 -> FLAGGED.
        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $this->postEvent($student, $attempt, ['event_type' => 'COPY_ATTEMPT']);
        $this->postEvent($student, $attempt, ['event_type' => 'PASTE_ATTEMPT']);

        $attempt->refresh();
        $this->assertSame(6, $attempt->risk_score);
        $this->assertSame('flagged', $attempt->integrity_status->value);
    }

    public function test_thresholds_change_status_correctly(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => true, 'prevent_paste' => true]
        );

        // +2 -> normal
        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $attempt->refresh();
        $this->assertSame('normal', $attempt->integrity_status->value);

        // +2 = 4 -> monitoring
        $this->postEvent($student, $attempt, ['event_type' => 'PASTE_ATTEMPT']);
        $attempt->refresh();
        $this->assertSame(4, $attempt->risk_score);
        $this->assertSame('monitoring', $attempt->integrity_status->value);

        // +2 = 6 -> flagged
        $this->postEvent($student, $attempt, ['event_type' => 'CUT_ATTEMPT']);
        $attempt->refresh();
        $this->assertSame(6, $attempt->risk_score);
        $this->assertSame('flagged', $attempt->integrity_status->value);
    }

    public function test_duplicate_events_within_window_do_not_inflate_risk(): void
    {
        // detect_tab_switch defaults on.
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $first = $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $first->assertStatus(201)->assertJsonPath('data.deduplicated', false);

        $second = $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);
        $second->assertStatus(201)->assertJsonPath('data.deduplicated', true);

        $attempt->refresh();
        // Only one stored event contributes.
        $this->assertSame(2, $attempt->risk_score);
        $this->assertSame(1, $attempt->integrityEvents()->count());
    }
}
