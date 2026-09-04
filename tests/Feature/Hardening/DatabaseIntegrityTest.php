<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\Exam;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

/**
 * Phase 5.2 — Database-level integrity. These invariants are protected by
 * unique constraints / DB behavior, not merely application checks, so they hold
 * even under concurrent requests:
 *
 *  - unique (competition_id, student_id) participant row,
 *  - unique (competition_id, participant_id) result,
 *  - unique active attempt per (student, exam) via active_key.
 */
class DatabaseIntegrityTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_duplicate_competition_participant_is_rejected_by_unique_constraint(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);

        // Direct DB insert of the same (competition, student) must be blocked by
        // the unique index regardless of application-level checks.
        $this->expectException(\Illuminate\Database\QueryException::class);

        CompetitionParticipant::create([
            'competition_id' => $competition->id,
            'student_id' => $student->id,
            'joined_at' => now(),
            'status' => 'registered',
        ]);
    }

    public function test_duplicate_competition_result_is_rejected_by_unique_constraint(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $this->joinCompetition($student, $competition)->assertStatus(201);
        $attempt = $this->makeSubmittedAttempt($student, $exam, 90, 90);

        $participant = CompetitionParticipant::where('competition_id', $competition->id)
            ->where('student_id', $student->id)
            ->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Models\CompetitionResult::create([
            'competition_id' => $competition->id,
            'participant_id' => $participant->id,
            'attempt_id' => $attempt->id,
            'score' => 90,
            'percentage' => 90,
            'completion_time' => 0,
            'completed_at' => now(),
            'qualified' => true,
        ]);
    }

    public function test_duplicate_active_attempt_is_rejected_by_active_key(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => 'in_progress',
            'active_key' => $student->id.':'.$exam->id,
            'attempt_number' => 1,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => 'in_progress',
            'active_key' => $student->id.':'.$exam->id,
            'attempt_number' => 2,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    public function test_exam_referenced_by_competition_cannot_be_deleted(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $this->makeActiveCompetition($teacher, $exam);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(409);
    }
}
