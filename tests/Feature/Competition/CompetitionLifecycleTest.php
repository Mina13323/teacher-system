<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionLifecycleTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function makeCourseAndExam()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_draft_can_be_published(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($teacher, $exam, [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(7),
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');
    }

    public function test_publish_requires_a_scheduling_window(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($teacher, $exam); // no starts_at/ends_at

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/publish")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_published_competition_is_promoted_to_active_when_window_opens(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makePublishedCompetition($teacher, $exam); // starts_at in past

        // Teacher show triggers lazy finalization.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_active_competition_is_finalized_when_window_closes(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => CompetitionStatus::Active->value,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(), // already closed
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ended');
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();

        // Publishing an active competition is invalid.
        $active = $this->makeActiveCompetition($teacher, $exam);
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$active->id}/publish")
            ->assertStatus(422);

        // Archiving an active competition is invalid (must end first).
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$active->id}/archive")
            ->assertStatus(422);
    }

    public function test_competition_status_machine_rejects_invalid_moves(): void
    {
        $this->assertTrue(CompetitionStatus::Draft->canTransitionTo(CompetitionStatus::Published));
        $this->assertTrue(CompetitionStatus::Published->canTransitionTo(CompetitionStatus::Active));
        $this->assertTrue(CompetitionStatus::Active->canTransitionTo(CompetitionStatus::Ended));
        $this->assertTrue(CompetitionStatus::Ended->canTransitionTo(CompetitionStatus::Archived));

        $this->assertFalse(CompetitionStatus::Ended->canTransitionTo(CompetitionStatus::Active));
        $this->assertFalse(CompetitionStatus::Archived->canTransitionTo(CompetitionStatus::Active));
        $this->assertFalse(CompetitionStatus::Archived->canTransitionTo(CompetitionStatus::Draft));
        $this->assertFalse(CompetitionStatus::Active->canTransitionTo(CompetitionStatus::Draft));
        $this->assertFalse(CompetitionStatus::Draft->canTransitionTo(CompetitionStatus::Ended));
    }
}
