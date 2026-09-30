<?php

namespace Tests\Feature\Exam;

use App\Enums\AttemptOutcome;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityReviewDecision;
use App\Enums\IntegrityStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * ONE outcome definition everywhere (P0.2) + pass-boundary precision (P0.6).
 *
 * The same attempt must read identically on the student result screen, the
 * teacher attempt detail and the analytics payloads.
 */
class AttemptOutcomeTest extends ApiTestCase
{
    use InteractsWithExams;

    private function makeGradedAttempt(array $attemptAttributes = [], bool $published = true): ExamAttempt
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $student = $this->createUserWithRole(UserRole::Student);

        return ExamAttempt::factory()->create(array_merge([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => ExamAttemptStatus::Submitted->value,
            'pass_percentage' => 60,
            'percentage' => 75,
            'score' => 3,
            'grades_published_at' => $published ? now() : null,
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ], $attemptAttributes));
    }

    public function test_normal_pass(): void
    {
        $attempt = $this->makeGradedAttempt(['percentage' => 75, 'raw_percentage' => 75.0]);

        $this->assertSame(AttemptOutcome::Passed, $attempt->outcome());
        $this->assertTrue($attempt->isPassed());
    }

    public function test_normal_fail(): void
    {
        $attempt = $this->makeGradedAttempt(['percentage' => 40, 'raw_percentage' => 40.0]);

        $this->assertSame(AttemptOutcome::Failed, $attempt->outcome());
        $this->assertFalse($attempt->isPassed());
    }

    public function test_flagged_attempt_with_passing_score_is_pending_review_not_passed(): void
    {
        $attempt = $this->makeGradedAttempt([
            'percentage' => 90,
            'raw_percentage' => 90.0,
            'integrity_status' => IntegrityStatus::Flagged->value,
        ]);

        $this->assertSame(AttemptOutcome::PendingReview, $attempt->outcome());
        $this->assertFalse($attempt->isPassed());
    }

    public function test_flagged_attempt_with_failing_score_is_pending_review_not_failed(): void
    {
        $attempt = $this->makeGradedAttempt([
            'percentage' => 20,
            'raw_percentage' => 20.0,
            'integrity_status' => IntegrityStatus::Flagged->value,
        ]);

        $this->assertSame(AttemptOutcome::PendingReview, $attempt->outcome());
    }

    public function test_teacher_confirmed_violation_disqualifies(): void
    {
        $attempt = $this->makeGradedAttempt([
            'percentage' => 90,
            'raw_percentage' => 90.0,
            'integrity_status' => IntegrityStatus::Flagged->value,
        ]);

        $attempt->integrityReviews()->create([
            'reviewed_by' => $this->createUserWithRole(UserRole::Teacher)->id,
            'decision' => IntegrityReviewDecision::Flagged->value,
            'note' => 'Confirmed.',
            'reviewed_at' => now(),
        ]);

        $this->assertSame(AttemptOutcome::Disqualified, $attempt->fresh()->outcome());
    }

    public function test_cleared_flag_becomes_definitive_again(): void
    {
        $attempt = $this->makeGradedAttempt([
            'percentage' => 90,
            'raw_percentage' => 90.0,
            'integrity_status' => IntegrityStatus::Cleared->value,
        ]);

        $this->assertSame(AttemptOutcome::Passed, $attempt->outcome());
    }

    public function test_unpublished_grade_is_pending_review(): void
    {
        $attempt = $this->makeGradedAttempt(['percentage' => 90, 'raw_percentage' => 90.0], published: false);

        $this->assertSame(AttemptOutcome::PendingReview, $attempt->outcome());
    }

    public function test_expired_attempt_is_expired(): void
    {
        $attempt = $this->makeGradedAttempt([
            'status' => ExamAttemptStatus::Expired->value,
            'percentage' => null,
            'score' => null,
        ]);

        $this->assertSame(AttemptOutcome::Expired, $attempt->outcome());
    }

    public function test_legacy_rows_without_raw_percentage_keep_historical_rounded_semantics(): void
    {
        // Historical rows compare the stored rounded percentage: 60 >= 60 passes,
        // exactly as every screen showed before this change.
        $attempt = $this->makeGradedAttempt(['percentage' => 60, 'raw_percentage' => null]);

        $this->assertSame(AttemptOutcome::Passed, $attempt->outcome());
    }

    public function test_raw_percentage_decides_the_boundary_not_the_rounded_display(): void
    {
        // Display 60% but raw 59.5 with a 60 threshold: FAILED.
        $attempt = $this->makeGradedAttempt(['percentage' => 60, 'raw_percentage' => 59.5]);
        $this->assertSame(AttemptOutcome::Failed, $attempt->outcome());

        // Exactly at the threshold: PASSED.
        $attempt = $this->makeGradedAttempt(['percentage' => 60, 'raw_percentage' => 60.0]);
        $this->assertSame(AttemptOutcome::Passed, $attempt->outcome());

        // Just below: FAILED even though the display rounds to 60.
        $attempt = $this->makeGradedAttempt(['percentage' => 60, 'raw_percentage' => 59.999]);
        $this->assertSame(AttemptOutcome::Failed, $attempt->outcome());

        // Just above: PASSED.
        $attempt = $this->makeGradedAttempt(['percentage' => 60, 'raw_percentage' => 60.001]);
        $this->assertSame(AttemptOutcome::Passed, $attempt->outcome());

        // Clear below: FAILED with the display also below.
        $attempt = $this->makeGradedAttempt(['percentage' => 59, 'raw_percentage' => 59.49]);
        $this->assertSame(AttemptOutcome::Failed, $attempt->outcome());
    }

    public function test_same_attempt_reads_identically_on_student_and_teacher_surfaces(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $student = $this->createUserWithRole(UserRole::Student);

        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => ExamAttemptStatus::Submitted->value,
            'pass_percentage' => 60,
            'percentage' => 80,
            'raw_percentage' => 80.0,
            'score' => 4,
            'integrity_status' => IntegrityStatus::Flagged->value,
            'grades_published_at' => now(),
            'started_at' => now()->subHour(),
            'submitted_at' => now()->subMinutes(30),
        ]);

        // Student result surface.
        $studentView = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit") // idempotent re-submit returns the result
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('pending_review', $studentView['outcome']);
        // Not definitive: `passed` is deliberately absent (never a misleading false).
        $this->assertArrayNotHasKey('passed', $studentView);

        // Teacher detail surface.
        $teacherView = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('pending_review', $teacherView['outcome']);
        $this->assertArrayNotHasKey('passed', $teacherView);

        // Domain surface.
        $this->assertSame(AttemptOutcome::PendingReview, $attempt->fresh()->outcome());
    }
}
