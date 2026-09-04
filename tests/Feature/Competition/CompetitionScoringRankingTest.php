<?php

namespace Tests\Feature\Competition;

use App\Actions\Competition\RecalculateCompetitionLeaderboardAction;
use App\Enums\CompetitionScoringType;
use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\CompetitionResult;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionScoringRankingTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_high_score_scoring_picks_best_by_percentage(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam, [
            'scoring_type' => CompetitionScoringType::HighestScore->value,
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        // A: high raw score but low percentage. B: lower raw score, higher percentage.
        $this->makeSubmittedAttempt($student, $exam, 90, 60, now()->subMinutes(5), now()->subMinutes(25), 1);
        $this->makeSubmittedAttempt($student, $exam, 80, 95, now()->subMinutes(4), now()->subMinutes(24), 2);

        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $result = CompetitionResult::where('competition_id', $competition->id)->firstOrFail();
        $this->assertSame(80, $result->score);
        $this->assertSame(95, $result->percentage);
    }

    public function test_best_attempt_scoring_picks_best_by_raw_score(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam, [
            'scoring_type' => CompetitionScoringType::BestAttempt->value,
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        $this->makeSubmittedAttempt($student, $exam, 90, 60, now()->subMinutes(5), now()->subMinutes(25), 1);
        $this->makeSubmittedAttempt($student, $exam, 80, 95, now()->subMinutes(4), now()->subMinutes(24), 2);

        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $result = CompetitionResult::where('competition_id', $competition->id)->firstOrFail();
        $this->assertSame(90, $result->score);
        $this->assertSame(60, $result->percentage);
    }

    public function test_result_is_derived_server_side_not_from_client(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        // The client tries to slip a score/rank/percentage into the join body.
        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/competitions/{$competition->id}/join", [
                'score' => 9999,
                'rank' => 1,
                'percentage' => 100,
                'completion_time' => 1,
            ]);

        $response->assertStatus(201);

        $this->makeSubmittedAttempt($student, $exam, 55, 40);

        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $result = CompetitionResult::where('competition_id', $competition->id)->firstOrFail();
        // Values come from the trusted attempt, never from the client body.
        $this->assertSame(55, $result->score);
        $this->assertSame(40, $result->percentage);
        $this->assertNotSame(9999, $result->score);
    }

    public function test_ranking_higher_score_first_with_standard_tie_handling(): void
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

        // A and B share top score 100; C has 95.
        $this->makeSubmittedAttempt($a, $exam, 100, 100, now()->subMinutes(5), now()->subMinutes(20), 1);
        $this->makeSubmittedAttempt($b, $exam, 100, 100, now()->subMinutes(9), now()->subMinutes(10), 1);
        $this->makeSubmittedAttempt($c, $exam, 95, 95, now()->subMinutes(5), now()->subMinutes(20), 1);

        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $results = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('rank')
            ->get();

        $ranks = $results->pluck('rank')->all();
        $scores = $results->pluck('score')->all();

        $this->assertSame([100, 100, 95], $scores);
        // Standard competition ranking: 1, 1, 3.
        $this->assertSame([1, 1, 3], $ranks);

        // Faster finisher among the tied top scores is ordered first.
        $this->assertSame($b->id, $results[0]->participant->student_id);
    }

    public function test_recalculated_rankings_are_identical(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        foreach (range(1, 3) as $i) {
            $student = $this->createUserWithRole(UserRole::Student);
            $this->enrollStudent($student, $course);
            $this->joinCompetition($student, $competition)->assertStatus(201);
            $this->makeSubmittedAttempt($student, $exam, 50 * $i, 50 * $i, now()->subMinutes(30 - $i));
        }

        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);
        $first = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('participant_id')
            ->get(['participant_id', 'rank'])
            ->map(fn ($r) => [$r->participant_id, $r->rank])
            ->all();

        // Recalculate again.
        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);
        $second = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('participant_id')
            ->get(['participant_id', 'rank'])
            ->map(fn ($r) => [$r->participant_id, $r->rank])
            ->all();

        $this->assertSame($first, $second);
    }
}
