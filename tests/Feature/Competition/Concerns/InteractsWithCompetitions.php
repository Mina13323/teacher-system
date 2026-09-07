<?php

namespace Tests\Feature\Competition\Concerns;

use App\Models\Competition;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Enums\CompetitionStatus;
use Illuminate\Support\Carbon;

/**
 * Shared helpers for Phase 5 competition feature tests.
 */
trait InteractsWithCompetitions
{
    protected function makeCompetition(User $teacher, Exam $exam, array $attributes = []): Competition
    {
        return Competition::factory()->create(array_merge([
            'created_by' => $teacher->id,
            'exam_id' => $exam->id,
        ], $attributes));
    }

    protected function makePublishedCompetition(User $teacher, Exam $exam, array $attributes = []): Competition
    {
        return $this->makeCompetition($teacher, $exam, array_merge([
            'status' => CompetitionStatus::Published->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ], $attributes));
    }

    protected function makeActiveCompetition(User $teacher, Exam $exam, array $attributes = []): Competition
    {
        return $this->makeCompetition($teacher, $exam, array_merge([
            'status' => CompetitionStatus::Active->value,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ], $attributes));
    }

    protected function makeEndedCompetition(User $teacher, Exam $exam, array $attributes = []): Competition
    {
        return $this->makeCompetition($teacher, $exam, array_merge([
            'status' => CompetitionStatus::Ended->value,
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subHour(),
        ], $attributes));
    }

    protected function enrollStudent(User $student, \App\Models\Course $course): void
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);
    }

    protected function joinCompetition(User $student, Competition $competition): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/competitions/{$competition->id}/join");
    }

    /**
     * Register a participant directly, bypassing the join endpoint. Used by tests
     * that model a competition whose window has already closed (so the endpoint
     * would correctly reject a new join) yet which still had people participating
     * while it was open — those participants must exist as fixtures.
     */
    protected function registerParticipantDirectly(User $student, Competition $competition, ?\Carbon\Carbon $joinedAt = null): \App\Models\CompetitionParticipant
    {
        return \App\Models\CompetitionParticipant::create([
            'competition_id' => $competition->getKey(),
            'student_id' => $student->getKey(),
            'joined_at' => $joinedAt ?? now()->subHour(),
            'status' => \App\Enums\CompetitionParticipantStatus::Registered->value,
        ]);
    }

    /**
     * Create a submitted exam attempt for a student on a given exam.
     *
     * @return \App\Models\ExamAttempt
     */
    protected function makeSubmittedAttempt(
        User $student,
        Exam $exam,
        int $score,
        int $percentage,
        ?Carbon $submittedAt = null,
        ?Carbon $startedAt = null,
        ?int $attemptNumber = null
    ): ExamAttempt {
        $startedAt ??= now()->subMinutes(20);
        $submittedAt ??= now()->subMinutes(5);

        return ExamAttempt::factory()->submitted()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'attempt_number' => $attemptNumber ?? $this->nextAttemptNumber($student, $exam),
            'score' => $score,
            'percentage' => $percentage,
            'started_at' => $startedAt,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function nextAttemptNumber(User $student, Exam $exam): int
    {
        return ExamAttempt::where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->count() + 1;
    }
}
