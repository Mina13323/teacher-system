<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * Representative cross-phase security matrix assertions. Confirms the main
 * authorization / privacy boundaries hold across domains:
 *
 *  - Authentication required for protected routes.
 *  - Student cannot access a competition's leaderboard/own-position unless
 *    they have actually joined it (protects Student A from Student B's result).
 *  - Student cannot even view a competition for a course they are not enrolled in.
 *  - Students cannot reach teacher competition-management routes.
 */
class SecurityMatrixTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/student/competitions')->assertStatus(401);
        $this->getJson('/api/v1/teacher/competitions')->assertStatus(401);
    }

    public function test_enrolled_but_not_joined_student_cannot_view_leaderboard(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        // Student A is enrolled but never joins; they must not see the
        // leaderboard (which would contain Student B's result).
        $viewer = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($viewer, $course);

        $other = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($other, $course);
        $this->joinCompetition($other, $competition)->assertStatus(201);
        $this->makeSubmittedAttempt($other, $exam, 90, 90);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}/leaderboard")
            ->assertStatus(403);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}/leaderboard/me")
            ->assertStatus(403);
    }

    public function test_student_not_enrolled_in_the_course_cannot_view_competition(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $outsider = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/student/competitions/{$competition->id}")
            ->assertStatus(403);
    }

    public function test_students_cannot_reach_teacher_competition_management_routes(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/teacher/competitions', ['title' => 'X', 'exam_id' => $exam->id])
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/recalculate-leaderboard")
            ->assertStatus(403);
    }

    public function test_student_not_enrolled_in_the_course_cannot_discover_the_competition_in_list(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $this->makeActiveCompetition($teacher, $exam);

        $outsider = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/v1/student/competitions')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    public function test_student_cannot_trigger_arbitrary_recalculation_with_payload(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);

        // Even sending a payload cannot influence ranking — the endpoint is
        // teacher-only and ignores any body.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/recalculate-leaderboard", [
                'score' => 9999,
                'rank' => 1,
            ])->assertStatus(403);

        $this->assertDatabaseCount('competition_results', 0);
    }
}
