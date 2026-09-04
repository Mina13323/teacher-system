<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Course;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * Cross-phase deletion integrity: an exam (or a course containing such an exam)
 * that is referenced by a competition must not be deleted, because deleting it
 * would destroy the competition's historical ranking source. The API must
 * return a clean conflict response rather than a raw SQL error.
 */
class CompetitionDeletionGuardTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    public function test_teacher_cannot_delete_exam_referenced_by_a_competition(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);
        $this->makeCompetition($teacher, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
        $this->assertDatabaseHas('competitions', ['exam_id' => $exam->id]);
    }

    public function test_teacher_can_delete_exam_not_referenced_by_competition(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('exams', ['id' => $exam->id]);
    }

    public function test_teacher_cannot_delete_course_containing_a_competition_referenced_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);
        $this->makeCompetition($teacher, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/courses/{$course->id}")
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('courses', ['id' => $course->id]);
    }

    public function test_teacher_can_delete_course_without_competition_referenced_exams(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        // A course with a normal exam (no competition) deletes fine.
        Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/courses/{$course->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
    }
}
