<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Enums\UserRole;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * Cross-phase lifecycle consistency: a published competition whose scheduling
 * window has already opened must be reported as ACTIVE everywhere, including
 * the list/discovery endpoint — not just in show/join/leaderboard. This
 * satisfies the requirement to centralize lifecycle resolution.
 */
class CompetitionLifecycleConsistencyTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    public function test_student_list_shows_published_competition_as_active_once_window_opens(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        // Published with the window already open -> effective status is active.
        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => CompetitionStatus::Published->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/competitions')
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.status', 'active');

        // The persisted state now matches the resolved state.
        $this->assertSame(CompetitionStatus::Active->value, $competition->fresh()->status->value);
    }

    public function test_student_show_matches_list_status(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => CompetitionStatus::Published->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $listStatus = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/competitions')
            ->json('data.data.0.status');

        $showStatus = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}")
            ->json('data.status');

        $this->assertSame('active', $listStatus);
        $this->assertSame('active', $showStatus);
    }

    public function test_published_competition_before_window_stays_published_in_list(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => CompetitionStatus::Published->value,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(7),
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/competitions')
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.status', 'published');

        $this->assertSame(CompetitionStatus::Published->value, $competition->fresh()->status->value);
    }
}
