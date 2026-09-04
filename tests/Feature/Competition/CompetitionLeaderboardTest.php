<?php

namespace Tests\Feature\Competition;

use App\Enums\UserRole;
use App\Models\CompetitionResult;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionLeaderboardTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_student_leaderboard_does_not_expose_private_data(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $viewer = $this->createUserWithRole(UserRole::Student);
        $other = $this->createUserWithRole(UserRole::Student);

        foreach ([$viewer, $other] as $student) {
            $this->enrollStudent($student, $course);
            $this->joinCompetition($student, $competition)->assertStatus(201);
        }

        $this->makeSubmittedAttempt($viewer, $exam, 80, 80);
        $this->makeSubmittedAttempt($other, $exam, 90, 90);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}/leaderboard");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $rows = $response->json('data.data');
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('rank', $row);
            $this->assertArrayHasKey('student_display_name', $row);
            $this->assertArrayHasKey('score', $row);
            $this->assertArrayHasKey('percentage', $row);
            $this->assertArrayHasKey('completion_time', $row);

            $this->assertArrayNotHasKey('email', $row);
            $this->assertArrayNotHasKey('student_id', $row);
            $this->assertArrayNotHasKey('participant_id', $row);
            $this->assertArrayNotHasKey('attempt_id', $row);
        }
    }

    public function test_student_can_see_own_position_and_total(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);
        $this->makeSubmittedAttempt($student, $exam, 70, 70);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}/leaderboard/me");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_participants', 1)
            ->assertJsonPath('data.score', 70)
            ->assertJsonPath('data.percentage', 70)
            ->assertJsonPath('data.rank', 1);
    }

    public function test_student_without_result_has_no_rank(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}/leaderboard/me");

        $response->assertStatus(200)
            ->assertJsonPath('data.rank', null)
            ->assertJsonPath('data.total_participants', 1);
    }

    public function test_disqualified_participant_is_excluded_but_result_preserved(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $a = $this->createUserWithRole(UserRole::Student);
        $b = $this->createUserWithRole(UserRole::Student);

        foreach ([$a, $b] as $student) {
            $this->enrollStudent($student, $course);
            $this->joinCompetition($student, $competition)->assertStatus(201);
        }

        $this->makeSubmittedAttempt($a, $exam, 95, 95);
        $this->makeSubmittedAttempt($b, $exam, 60, 60);

        // Teacher leaderboard (recalc) so both appear.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/leaderboard")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.data');

        // Disqualify the top scorer A. The disqualify route takes the
        // participant id, not the user id, so resolve the participant row.
        $participantA = \App\Models\CompetitionParticipant::where('competition_id', $competition->id)
            ->where('student_id', $a->id)
            ->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/participants/{$participantA->id}/disqualify")
            ->assertStatus(200);

        // Still one result row (historical data preserved).
        $this->assertSame(2, CompetitionResult::where('competition_id', $competition->id)->count());

        // Leaderboard excludes the disqualified participant.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/leaderboard")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }
}
