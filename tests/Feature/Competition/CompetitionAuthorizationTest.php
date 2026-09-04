<?php

namespace Tests\Feature\Competition;

use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionAuthorizationTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_student_cannot_modify_a_competition(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/teacher/competitions', ['title' => 'X', 'exam_id' => $exam->id])
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/teacher/competitions/{$competition->id}", ['title' => 'X'])
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/publish")
            ->assertStatus(403);
    }

    public function test_student_cannot_disqualify_anyone(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/recalculate-leaderboard")
            ->assertStatus(403);
    }

    public function test_teacher_cannot_access_another_teachers_competition(): void
    {
        [$owner, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($owner, $exam);
        $other = $this->createUserWithRole(UserRole::Teacher);

        // Other teacher's course/exam, but this competition belongs to owner.
        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(403);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/teacher/competitions/{$competition->id}", ['title' => 'Hack'])
            ->assertStatus(403);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/recalculate-leaderboard")
            ->assertStatus(403);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/leaderboard")
            ->assertStatus(403);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/participants")
            ->assertStatus(403);
    }

    public function test_flagged_attempt_is_recorded_and_reviewable_not_auto_disqualified(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        ExamAttempt::factory()->submitted()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'score' => 80,
            'percentage' => 80,
            'integrity_status' => 'flagged',
            'risk_score' => 6,
        ]);

        // Teacher views the leaderboard first, which materializes the result and
        // still ranks the flagged attempt (it is not auto-disqualified).
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/leaderboard")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');

        // Teacher views participants; the flagged attempt is exposed for review.
        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/competitions/{$competition->id}/participants")
            ->assertStatus(200);

        $this->assertSame('flagged', $response->json('data.data.0.result.integrity_status'));
        $this->assertSame('completed', $response->json('data.data.0.status'));

        $this->assertDatabaseCount('competition_participants', 1);
        $this->assertDatabaseHas('competition_participants', [
            'competition_id' => $competition->id,
            'student_id' => $student->id,
            'status' => 'completed', // not auto-disqualified
        ]);
    }
}
