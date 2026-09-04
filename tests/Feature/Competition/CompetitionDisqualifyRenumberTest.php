<?php

namespace Tests\Feature\Competition;

use App\Enums\UserRole;
use App\Models\CompetitionResult;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * After a participant is explicitly disqualified, the remaining valid
 * participants must be re-ranked so the leaderboard has no gaps and the
 * previous #1 exclusion promotes the next best to #1.
 */
class CompetitionDisqualifyRenumberTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_disqualifying_top_ranked_participant_renumbers_remaining_ranks(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $a = $this->createUserWithRole(UserRole::Student);
        $b = $this->createUserWithRole(UserRole::Student);
        $c = $this->createUserWithRole(UserRole::Student);

        foreach ([$a, $b, $c] as $student) {
            $this->enrollStudent($student, $course);
            $this->joinCompetition($student, $competition)->assertStatus(201);
        }

        $this->makeSubmittedAttempt($a, $exam, 100, 100);
        $this->makeSubmittedAttempt($b, $exam, 90, 90);
        $this->makeSubmittedAttempt($c, $exam, 80, 80);

        // Materialize + rank.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/leaderboard")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data.data');

        $participantA = \App\Models\CompetitionParticipant::where('competition_id', $competition->id)
            ->where('student_id', $a->id)->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/participants/{$participantA->id}/disqualify")
            ->assertStatus(200);

        // Disqualified participant's historical result is preserved but unranked.
        $resultA = CompetitionResult::where('competition_id', $competition->id)
            ->where('participant_id', $participantA->id)
            ->firstOrFail();
        $this->assertSame(100, $resultA->score);
        $this->assertNull($resultA->rank);
        $this->assertFalse($resultA->qualified);
        // The participant itself is marked disqualified.
        $this->assertSame('disqualified', $participantA->fresh()->status->value);

        // Leaderboard excludes the disqualified participant and renumbers.
        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/leaderboard")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.data');

        $rows = $response->json('data.data');
        $ranks = collect($rows)->pluck('rank')->all();
        $this->assertSame([1, 2], $ranks);

        // B (score 90) is now #1 and C (score 80) is #2.
        $this->assertSame(90, $rows[0]['score']);
        $this->assertSame(80, $rows[1]['score']);
    }
}
