<?php

namespace Tests\Feature\Competition;

use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * Phase 5.2 — Competition result timing rule. An attempt only contributes to a
 * competition result if it was submitted before the competition closed
 * (`ends_at`). Attempts submitted at/after `ends_at` are outside the competition
 * and are not counted, so a student cannot submit after the window and game the
 * leaderboard.
 */
class CompetitionResultTimingTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    public function test_attempt_submitted_before_ends_at_counts(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $endsAt = now()->addDay();
        $competition = $this->makeActiveCompetition($teacher, $exam, ['ends_at' => $endsAt]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        // Submitted well before the window closes.
        $this->makeSubmittedAttempt(
            $student,
            $exam,
            90,
            90,
            now()->subHour(),
            now()->subHours(2)
        );

        // Recalculate -> the student has a result.
        app(\App\Actions\Competition\RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $this->assertDatabaseHas('competition_results', [
            'competition_id' => $competition->id,
            'score' => 90,
        ]);
    }

    public function test_attempt_submitted_after_ends_at_does_not_count(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        // The window is still open at join time (future ends_at) so the student
        // can join, but the attempt is completed AFTER the competition closed.
        $endsAt = now()->addDay();
        $competition = $this->makeActiveCompetition($teacher, $exam, ['ends_at' => $endsAt]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        // Submitted AFTER the competition closed (ends_at + 1 minute).
        $this->makeSubmittedAttempt(
            $student,
            $exam,
            90,
            90,
            $endsAt->copy()->addMinute(),
            now()->subHours(2)
        );

        app(\App\Actions\Competition\RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        // The late submission does not count -> no result row for this student.
        $this->assertDatabaseMissing('competition_results', [
            'competition_id' => $competition->id,
        ]);
    }

    public function test_attempt_without_ends_at_counts(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam, ['ends_at' => null]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        $this->makeSubmittedAttempt($student, $exam, 90, 90, now(), now()->subMinutes(20));

        app(\App\Actions\Competition\RecalculateCompetitionLeaderboardAction::class)->execute($competition);

        $this->assertDatabaseHas('competition_results', [
            'competition_id' => $competition->id,
            'score' => 90,
        ]);
    }

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }
}
