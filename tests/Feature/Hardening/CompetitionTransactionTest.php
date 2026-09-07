<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\CompetitionResult;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * Phase 5.2 — Competition transaction & concurrency invariants.
 *
 *  - Finalization (ACTIVE -> ENDED + frozen leaderboard) is atomic and idempotent.
 *  - Disqualification re-ranks the remaining valid participants and never leaves
 *    a disqualified participant ranked; repeated runs are stable.
 *  - A disqualified participant's historical result is preserved (not deleted).
 */
class CompetitionTransactionTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    private function enrolledStudent($course, Competition $competition, Exam $exam): \App\Models\User
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);
        $this->makeSubmittedAttempt($student, $exam, 90, 90);

        return $student;
    }

    public function test_disqualified_participant_never_remains_ranked_and_history_preserved(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $a = $this->enrolledStudent($course, $competition, $exam);
        $b = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($b, $course);
        $this->joinCompetition($b, $competition)->assertStatus(201);
        $this->makeSubmittedAttempt($b, $exam, 70, 70);

        $participantA = CompetitionParticipant::where('competition_id', $competition->id)
            ->where('student_id', $a->id)
            ->firstOrFail();

        // Disqualify the top-ranked participant (A).
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/participants/{$participantA->id}/disqualify")
            ->assertStatus(200);

        $fresh = $participantA->fresh();
        $this->assertTrue($fresh->isDisqualified());

        // Historical result preserved but unranked.
        $resultA = CompetitionResult::where('competition_id', $competition->id)
            ->where('participant_id', $participantA->id)
            ->firstOrFail();
        $this->assertNull($resultA->rank);
        $this->assertFalse($resultA->qualified);

        // The remaining valid participant must be re-ranked #1 (no gap).
        $resultB = CompetitionResult::where('competition_id', $competition->id)
            ->where('participant_id', CompetitionParticipant::where('competition_id', $competition->id)
                ->where('student_id', $b->id)->firstOrFail()->id)
            ->firstOrFail();
        $this->assertSame(1, $resultB->rank);

        // Disqualifying the same participant again is idempotent and stable.
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/participants/{$participantA->id}/disqualify")
            ->assertStatus(200);

        $resultB2 = CompetitionResult::where('competition_id', $competition->id)
            ->where('participant_id', $resultB->participant_id)
            ->firstOrFail();
        $this->assertSame(1, $resultB2->rank);
    }

    public function test_disqualification_does_not_delete_attempt_or_integrity_evidence(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $a = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($a, $course);
        $this->joinCompetition($a, $competition)->assertStatus(201);
        $attempt = $this->makeSubmittedAttempt($a, $exam, 90, 90);

        // Record an integrity event on the attempt (submitted attempts cannot
        // record via the API, so seed the evidence directly to assert it is
        // preserved through disqualification).
        \App\Models\ExamIntegrityEvent::factory()->create([
            'attempt_id' => $attempt->id,
            'event_type' => \App\Enums\IntegrityEventType::TabSwitch->value,
        ]);

        $participant = CompetitionParticipant::where('competition_id', $competition->id)
            ->where('student_id', $a->id)
            ->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/participants/{$participant->id}/disqualify")
            ->assertStatus(200);

        // The underlying exam attempt, its result, and the integrity event
        // evidence all survive disqualification.
        $this->assertNotNull(ExamAttempt::find($attempt->id));
        $this->assertDatabaseHas('competition_results', [
            'competition_id' => $competition->id,
            'participant_id' => $participant->id,
        ]);
        $this->assertDatabaseHas('exam_integrity_events', ['attempt_id' => $attempt->id]);
    }

    public function test_finalization_is_idempotent_and_keeps_frozen_ranks(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => \App\Enums\CompetitionStatus::Active->value,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(), // window closed -> finalize
        ]);

        $a = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($a, $course);
        // The window is already closed; registration happened earlier. Register
        // the participant directly rather than via the (correctly rejecting) join
        // endpoint.
        $this->registerParticipantDirectly($a, $competition, now()->subHours(5));
        // Must be submitted before the window closed (ends_at = now - 1 hour).
        $this->makeSubmittedAttempt($a, $exam, 90, 90, now()->subHours(3), now()->subHours(4));

        $b = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($b, $course);
        $this->registerParticipantDirectly($b, $competition, now()->subHours(5));
        $this->makeSubmittedAttempt($b, $exam, 70, 70, now()->subHours(3), now()->subHours(4));

        // First hit finalizes + freezes ranks.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ended');

        $first = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('participant_id')
            ->get(['participant_id', 'rank'])
            ->map(fn ($r) => [$r->participant_id, $r->rank])
            ->all();

        // Second hit must not re-rank or change the frozen result.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(200);

        $second = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('participant_id')
            ->get(['participant_id', 'rank'])
            ->map(fn ($r) => [$r->participant_id, $r->rank])
            ->all();

        $this->assertSame($first, $second);
        $this->assertSame('ended', $competition->fresh()->status->value);
    }
}
