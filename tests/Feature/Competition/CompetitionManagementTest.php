<?php

namespace Tests\Feature\Competition;

use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionManagementTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function makeCourseAndExam(UserRole $role = UserRole::Teacher)
    {
        $teacher = $this->createUserWithRole($role);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_teacher_can_create_competition(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/competitions', [
                'title' => 'Math Sprint',
                'description' => 'Weekly math challenge',
                'exam_id' => $exam->id,
                'starts_at' => now()->addDay()->toISOString(),
                'ends_at' => now()->addDays(7)->toISOString(),
                'max_participants' => 50,
                'scoring_type' => 'best_attempt',
                'ranking_type' => 'score_desc',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.title', 'Math Sprint')
            ->assertJsonPath('data.scoring_type', 'best_attempt');

        $this->assertDatabaseHas('competitions', [
            'title' => 'Math Sprint',
            'created_by' => $teacher->id,
            'exam_id' => $exam->id,
            'status' => 'draft',
        ]);
    }

    public function test_teacher_cannot_create_competition_for_another_teachers_exam(): void
    {
        [$owner, , $exam] = $this->makeCourseAndExam();
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/v1/teacher/competitions', [
                'title' => 'Nope',
                'exam_id' => $exam->id,
            ])->assertStatus(403);
    }

    public function test_teacher_can_list_own_competitions_only(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $this->makeCompetition($teacher, $exam, ['title' => 'C1']);
        $this->makeCompetition($teacher, $exam, ['title' => 'C2']);

        $other = $this->createUserWithRole(UserRole::Teacher);
        $oc = $this->createCourse($other, ['status' => 'published']);
        $oe = Exam::factory()->create(['course_id' => $oc->id, 'created_by' => $other->id]);
        $this->makeCompetition($other, $oe, ['title' => 'C3']);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/competitions')
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonCount(2, 'data.data');
    }

    public function test_teacher_can_update_own_competition(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($teacher, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/competitions/{$competition->id}", ['title' => 'Updated title'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated title');
    }

    public function test_teacher_cannot_update_another_teachers_competition(): void
    {
        [$owner, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($owner, $exam);
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/teacher/competitions/{$competition->id}", ['title' => 'Hack'])
            ->assertStatus(403);
    }

    public function test_teacher_can_delete_competition_without_participants(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($teacher, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('competitions', ['id' => $competition->id]);
    }

    public function test_teacher_cannot_delete_competition_with_participants(): void
    {
        [$teacher, $course, $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->joinCompetition($student, $competition)->assertStatus(201);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/competitions/{$competition->id}")
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('competitions', ['id' => $competition->id]);
    }

    public function test_teacher_can_archive_ended_competition(): void
    {
        [$teacher, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeEndedCompetition($teacher, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/archive")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_teacher_cannot_archive_another_teachers_competition(): void
    {
        [$owner, , $exam] = $this->makeCourseAndExam();
        $competition = $this->makeCompetition($owner, $exam);
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/teacher/competitions/{$competition->id}/archive")
            ->assertStatus(403);
    }
}
