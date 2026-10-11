<?php

namespace Tests\Feature\Exam;

use App\Actions\Exam\GradeEssayAnswerAction;
use App\Enums\ExamAttemptStatus;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\BuildsEssayAttempts;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P0 grading safety: publication never ends a running exam, essays are graded
 * only on handed-in attempts, an ungraded essay is never a zero, and a final
 * result is not published while essays are still ungraded.
 *
 * Attempts are created through the real start / answer / submit endpoints so
 * the frozen snapshot is the one students actually get.
 */
class GradingSafetyTest extends ApiTestCase
{
    use BuildsEssayAttempts, InteractsWithExams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEssayFixture();
    }


    // ------------------------------------------------------------------
    // P0-1: publication never ends a running exam
    // ------------------------------------------------------------------

    public function test_publishing_an_in_progress_attempt_is_refused_and_changes_nothing(): void
    {
        $exam = $this->exam();
        $attempt = $this->start($exam);
        $this->writeEssay($attempt, $this->essayIds($attempt)[0], 'Draft answer.');
        $before = $this->snapshotOf($attempt['id']);

        $this->publish($attempt)
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame($before, $this->snapshotOf($attempt['id']));
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertTrue($row->status->isInProgress());
        $this->assertNull($row->end_reason);
        $this->assertNull($row->grades_published_at);
        Notification::assertNothingSent();

        // The student keeps answering as if nothing happened.
        $this->writeEssay($attempt, $this->essayIds($attempt)[0], 'Final answer.');
    }

    public function test_an_overdue_attempt_is_finalized_by_the_expiry_policy_before_publication(): void
    {
        $exam = $this->exam(0);
        $attempt = $this->start($exam);
        $this->travelTo(ExamAttempt::findOrFail($attempt['id'])->expires_at->copy()->addSeconds(5));

        $this->publish($attempt)->assertOk();

        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame('auto_submit_at_deadline', $row->end_reason);
        $this->assertEquals($row->expires_at, $row->submitted_at, 'Submitted at the deadline, as the sweep does.');
        $this->assertNotNull($row->grades_published_at);
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);
    }

    public function test_a_strictly_expired_attempt_has_nothing_to_publish(): void
    {
        $exam = $this->exam(0, ['expiry_mode' => 'expire']);
        $attempt = $this->start($exam);
        $this->travelTo(ExamAttempt::findOrFail($attempt['id'])->expires_at->copy()->addSeconds(5));
        $this->artisan('attempts:process-expired')->assertSuccessful();
        $before = $this->snapshotOf($attempt['id']);

        $this->publish($attempt)->assertStatus(422);

        $this->assertSame($before, $this->snapshotOf($attempt['id']));
        $this->assertTrue(ExamAttempt::findOrFail($attempt['id'])->status->isExpired());
        Notification::assertNothingSent();
    }

    public function test_a_finalized_attempt_publishes_and_republishing_is_idempotent(): void
    {
        $exam = $this->exam(0);
        $attempt = $this->start($exam);
        $this->submit($attempt);

        $this->publish($attempt)->assertOk()->assertJsonPath('data.grades_published', true);
        $first = ExamAttempt::findOrFail($attempt['id'])->grades_published_at;
        $this->publish($attempt)->assertOk();

        $this->assertEquals($first, ExamAttempt::findOrFail($attempt['id'])->grades_published_at);
        $this->assertSame('submitted_by_student', ExamAttempt::findOrFail($attempt['id'])->end_reason);
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // P0-2: essays are graded only on handed-in attempts
    // ------------------------------------------------------------------

    public function test_grading_an_in_progress_attempt_is_refused_and_creates_no_grade(): void
    {
        $exam = $this->exam();
        $attempt = $this->start($exam);
        $essay = $this->essayIds($attempt)[0];
        $before = $this->snapshotOf($attempt['id']);

        $this->grade($attempt, $essay, 7)->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame($before, $this->snapshotOf($attempt['id']));
        $this->assertFalse(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->exists());
    }

    public function test_an_earlier_grade_never_survives_the_student_changing_the_essay(): void
    {
        $exam = $this->exam();
        $attempt = $this->start($exam);
        $essay = $this->essayIds($attempt)[0];
        $this->writeEssay($attempt, $essay, 'First draft.');

        // Refused while running...
        $this->grade($attempt, $essay, 9)->assertStatus(422);
        // ...and a grade left on the row by older code is cleared by the next edit.
        ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)
            ->update(['points_earned' => 9, 'graded_by' => $this->teacher->id, 'graded_at' => now()]);
        $this->writeEssay($attempt, $essay, 'Rewritten answer.');

        $row = ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->firstOrFail();
        $this->assertSame('Rewritten answer.', $row->answer_text);
        $this->assertNull($row->points_earned);
        $this->assertNull($row->graded_by);
        $this->assertNull($row->graded_at);

        $this->submit($attempt);
        $this->assertSame(ExamAttemptStatus::Grading, ExamAttempt::findOrFail($attempt['id'])->status, 'Still needs grading after hand-in.');
    }

    public function test_handed_in_attempts_can_be_graded_in_every_eligible_status(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        [$e1, $e2] = $this->essayIds($attempt);
        $this->writeEssay($attempt, $e1, 'One.');
        $this->writeEssay($attempt, $e2, 'Two.');
        $this->submit($attempt);
        $this->assertSame(ExamAttemptStatus::Grading, ExamAttempt::findOrFail($attempt['id'])->status);

        // grading -> still grading (one essay left)
        $this->grade($attempt, $e1, 6)->assertOk();
        $this->assertSame(ExamAttemptStatus::Grading, ExamAttempt::findOrFail($attempt['id'])->status);
        // grading -> submitted (all essays graded, not yet published)
        $this->grade($attempt, $e2, 4)->assertOk();
        $this->assertSame(ExamAttemptStatus::Submitted, ExamAttempt::findOrFail($attempt['id'])->status);
        // submitted: re-grade
        $this->grade($attempt, $e2, 5)->assertOk();
        // published: re-grade
        $this->publish($attempt)->assertOk();
        $this->grade($attempt, $e2, 3)->assertOk();
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame(ExamAttemptStatus::Published, $row->status);
        $this->assertSame(9, (int) $row->score, 'MCQ unanswered (0) + 6 + 3.');
    }

    public function test_a_strictly_expired_attempt_is_not_graded(): void
    {
        $exam = $this->exam(1, ['expiry_mode' => 'expire']);
        $attempt = $this->start($exam);
        $this->writeEssay($attempt, $this->essayIds($attempt)[0], 'Text.');
        $this->travelTo(ExamAttempt::findOrFail($attempt['id'])->expires_at->copy()->addSeconds(5));

        $this->grade($attempt, $this->essayIds($attempt)[0], 5)->assertStatus(422);
        $this->assertTrue(ExamAttempt::findOrFail($attempt['id'])->status->isExpired());
    }

    public function test_grading_decides_the_status_from_the_locked_row_not_a_stale_model(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $essay = $this->essayIds($attempt)[0];
        $this->writeEssay($attempt, $essay, 'Text.');
        $this->submit($attempt);

        // The request bound this model while it read `grading`, unpublished...
        $stale = ExamAttempt::findOrFail($attempt['id']);
        // ...then a concurrent request recorded a publication.
        ExamAttempt::whereKey($attempt['id'])->update(['grades_published_at' => now()]);

        app(GradeEssayAnswerAction::class)->execute($this->teacher, $stale, $essay, 8);

        $this->assertSame(ExamAttemptStatus::Published, ExamAttempt::findOrFail($attempt['id'])->status);
    }

    // ------------------------------------------------------------------
    // P0-3: an ungraded essay is not a zero
    // ------------------------------------------------------------------

    private function staffQuestion(array $attempt, int $questionId): array
    {
        return collect($this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt['id']}")
            ->assertOk()
            ->json('data.questions'))->firstWhere('id', $questionId);
    }

    public function test_an_ungraded_essay_is_null_and_an_awarded_zero_is_zero(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        [$written, $blank] = $this->essayIds($attempt);
        $this->writeEssay($attempt, $written, 'Answer.');
        $this->submit($attempt);

        $this->assertNull($this->staffQuestion($attempt, $written)['points_earned'], 'Written but ungraded.');
        $this->assertNull($this->staffQuestion($attempt, $blank)['points_earned'], 'No answer row at all.');

        $this->grade($attempt, $written, 0)->assertOk();
        $this->assertSame(0, $this->staffQuestion($attempt, $written)['points_earned']);
        $this->assertNull($this->staffQuestion($attempt, $blank)['points_earned']);

        // The auto-graded choice question still reports its awarded points.
        $this->assertSame(0, $this->staffQuestion($attempt, $this->choiceId($attempt))['points_earned']);
    }

    public function test_the_award_must_be_an_explicit_whole_number_within_the_frozen_maximum(): void
    {
        $exam = $this->exam();
        $attempt = $this->start($exam);
        $essay = $this->essayIds($attempt)[0];
        $this->writeEssay($attempt, $essay, 'Answer.');
        $this->submit($attempt);

        foreach ([null, '', -1, 11, 2.5, 'ten'] as $bad) {
            $this->grade($attempt, $essay, $bad)->assertStatus(422);
        }
        $this->assertNull(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->value('points_earned'));

        $this->grade($attempt, $essay, 10)->assertOk();
        $this->assertSame(10, ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->value('points_earned'));
    }

    public function test_the_student_review_is_unchanged_by_the_staff_representation(): void
    {
        $exam = $this->exam(1, ['show_result_immediately' => false]);
        $attempt = $this->start($exam);
        $essay = $this->essayIds($attempt)[0];
        $this->writeEssay($attempt, $essay, 'Answer.');
        $this->submit($attempt);
        $this->grade($attempt, $essay, 0)->assertOk();
        $this->publish($attempt)->assertOk();

        $review = collect($this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt['id']}")
            ->assertOk()
            ->json('data.questions'))->firstWhere('id', $essay)['review'] ?? null;

        $this->assertNotNull($review);
        $this->assertSame(0, $review['points_earned']);
    }

    // ------------------------------------------------------------------
    // P0-4: no final result while essays are ungraded
    // ------------------------------------------------------------------

    public function test_publication_is_refused_with_the_number_of_ungraded_essays(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        [$written, $blank] = $this->essayIds($attempt);
        $this->writeEssay($attempt, $written, 'Private essay text.');
        $this->submit($attempt);
        $before = $this->snapshotOf($attempt['id']);

        $response = $this->publish($attempt)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'essays_ungraded')
            ->assertJsonPath('data.ungraded_essays', 2);
        $this->assertStringNotContainsString('Private essay text.', $response->getContent());

        $this->assertSame($before, $this->snapshotOf($attempt['id']));
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame(ExamAttemptStatus::Grading, $row->status);
        $this->assertNull($row->grades_published_at);
        Notification::assertNothingSent();

        // The blank essay still counts until it is explicitly graded.
        $this->grade($attempt, $written, 7)->assertOk();
        $this->publish($attempt)->assertStatus(422)->assertJsonPath('data.ungraded_essays', 1);
        $this->assertFalse(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $blank)->exists(),
            'A refused publication never invents a graded row for the blank essay.');

        // An explicit zero is a grade: publication now succeeds.
        $this->grade($attempt, $blank, 0)->assertOk();
        $this->publish($attempt)->assertOk()->assertJsonPath('data.grades_published', true);
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame(ExamAttemptStatus::Published, $row->status);
        $this->assertSame(7, (int) $row->score);
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);
    }

    public function test_a_refused_publication_rolls_back_scoring_of_a_never_scored_attempt(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $this->writeEssay($attempt, $this->essayIds($attempt)[0], 'Text.');
        $this->submit($attempt);
        // A legacy handed-in row that was never scored.
        ExamAttempt::whereKey($attempt['id'])->update(['scored_at' => null, 'score' => null, 'percentage' => null]);
        $before = $this->snapshotOf($attempt['id']);

        $this->publish($attempt)->assertStatus(422)->assertJsonPath('data.ungraded_essays', 1);

        $this->assertSame($before, $this->snapshotOf($attempt['id']));
    }

    public function test_changing_a_published_score_notifies_once_and_identical_grades_do_not(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $essay = $this->essayIds($attempt)[0];
        $this->writeEssay($attempt, $essay, 'Text.');
        $this->submit($attempt);
        $this->grade($attempt, $essay, 4)->assertOk();
        Notification::assertNothingSent();
        $this->publish($attempt)->assertOk();
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);

        // Same value again (e.g. a repeated click): no new notification.
        $this->grade($attempt, $essay, 4)->assertOk();
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);

        // An authorized regrade changes the published score: the student is told.
        $this->grade($attempt, $essay, 9)->assertOk();
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 2);
        $this->assertSame(9, (int) ExamAttempt::findOrFail($attempt['id'])->score);

        // Re-publishing the regraded result does not notify again.
        $this->publish($attempt)->assertOk();
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 2);
    }
}
