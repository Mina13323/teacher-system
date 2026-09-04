<?php

namespace Tests\Feature\Competition;

use App\Actions\Competition\RecalculateCompetitionLeaderboardAction;
use App\Enums\CompetitionStatus;
use App\Enums\UserRole;
use App\Models\CompetitionResult;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionFinalizationTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_ended_competition_has_a_frozen_leaderboard_and_repeated_finalization_is_safe(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => CompetitionStatus::Active->value,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(), // window closed -> will finalize
        ]);

        $students = collect(range(1, 2))->map(fn () => $this->createUserWithRole(UserRole::Student));

        foreach ($students as $student) {
            $this->enrollStudent($student, $course);
            $this->joinCompetition($student, $competition)->assertStatus(201);
        }

        $this->makeSubmittedAttempt($students[0], $exam, 90, 90);
        $this->makeSubmittedAttempt($students[1], $exam, 70, 70);

        // Hitting the teacher view lazily finalizes the competition and freezes
        // the leaderboard snapshot.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ended');

        $this->assertSame(2, CompetitionResult::where('competition_id', $competition->id)->count());

        $first = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('participant_id')
            ->get(['participant_id', 'rank', 'score'])
            ->map(fn ($r) => [$r->participant_id, $r->rank, $r->score])
            ->all();

        // Explicitly recalculate again: must be idempotent and produce the same
        // frozen ranks.
        app(RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $second = CompetitionResult::where('competition_id', $competition->id)
            ->orderBy('participant_id')
            ->get(['participant_id', 'rank', 'score'])
            ->map(fn ($r) => [$r->participant_id, $r->rank, $r->score])
            ->all();

        $this->assertSame($first, $second);

        // No new participation is accepted once ended.
        $late = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($late, $course);
        $this->joinCompetition($late, $competition)->assertStatus(403);
    }
}
