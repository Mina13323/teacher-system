<?php

namespace Tests\Feature\Integrity;

use App\Models\ExamIntegrityReview;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

class IntegrityReviewTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_teacher_can_review_and_decision_is_recorded(): void
    {
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'CLEARED',
                'note' => 'Browser notification caused focus loss.',
            ])->assertStatus(201)
            ->assertJsonPath('data.decision', 'CLEARED')
            ->assertJsonPath('data.note', 'Browser notification caused focus loss.');

        $this->assertDatabaseHas('exam_integrity_reviews', [
            'attempt_id' => $attempt->id,
            'reviewed_by' => $teacher->id,
            'decision' => 'CLEARED',
            'note' => 'Browser notification caused focus loss.',
        ]);
    }

    public function test_review_updates_attempt_status_to_cleared(): void
    {
        [$student, , , $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'CLEARED',
            ])->assertStatus(201);

        $this->assertSame('cleared', $attempt->fresh()->integrity_status->value);
    }

    public function test_review_updates_attempt_status_to_flagged(): void
    {
        [$student, , , $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'FLAGGED',
            ])->assertStatus(201);

        $this->assertSame('flagged', $attempt->fresh()->integrity_status->value);
    }

    public function test_previous_review_history_is_preserved(): void
    {
        [$student, , , $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'FLAGGED',
                'note' => 'Initial review.',
            ])->assertStatus(201);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'CLEARED',
                'note' => 'Confirmed not cheating.',
            ])->assertStatus(201);

        $this->assertSame(2, ExamIntegrityReview::where('attempt_id', $attempt->id)->count());
        $this->assertDatabaseHas('exam_integrity_reviews', [
            'attempt_id' => $attempt->id,
            'decision' => 'FLAGGED',
            'note' => 'Initial review.',
        ]);
    }

    public function test_teacher_integrity_endpoint_includes_evidence(): void
    {
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => true]
        );

        $this->postEvent($student, $attempt, ['event_type' => 'COPY_ATTEMPT']);
        $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}/integrity")
            ->assertStatus(200)
            ->assertJsonPath('data.attempt_id', $attempt->id)
            ->assertJsonPath('data.integrity_status', 'monitoring')
            ->assertJsonPath('data.risk_score', 4)
            ->assertJsonPath('data.event_count', 2)
            ->assertJsonCount(2, 'data.events');
    }
}
