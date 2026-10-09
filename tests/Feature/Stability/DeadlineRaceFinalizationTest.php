<?php

namespace Tests\Feature\Stability;

use App\Actions\Exam\SaveExamAnswerAction;
use App\Actions\Exam\SubmitExamAttemptAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * A save or submit can pass the "is it expired?" check and then find the
 * deadline passed once it holds the row lock. Finalizing inside that
 * transaction and then throwing used to roll the finalization back, leaving
 * the attempt in progress until the scheduled sweep picked it up.
 */
class DeadlineRaceFinalizationTest extends ApiTestCase
{
    use InteractsWithExams;

    /** @return array{0: ExamAttempt, 1: ExamAttempt} the stale in-memory copy and the row */
    private function attemptWhoseDeadlinePassedAfterTheCheck(array $examAttributes = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, array_merge(['duration_minutes' => 30, 'max_attempts' => 5], $examAttributes));
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $attemptId = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->json('data.id');

        $stale = ExamAttempt::findOrFail($attemptId);
        $stale->load('exam');

        // The row's deadline is now in the past; the in-memory copy that
        // already passed the first check still says there is time left.
        ExamAttempt::whereKey($attemptId)->update(['expires_at' => now()->subSecond()]);

        return [$stale, ExamAttempt::findOrFail($attemptId)];
    }

    public function test_a_late_answer_is_refused_and_the_attempt_stays_finalized(): void
    {
        [$stale, $row] = $this->attemptWhoseDeadlinePassedAfterTheCheck();
        $question = $row->attemptQuestions()->first();
        $option = $question->attemptOptions()->first();

        try {
            app(SaveExamAnswerAction::class)->execute($stale, $question->question_id, null, null, [$option->option_id]);
            $this->fail('The late answer must be refused.');
        } catch (InvalidAttemptStateException $e) {
            $this->assertSame('This attempt has expired.', $e->getMessage());
        }

        $row->refresh();
        $this->assertNotSame(ExamAttemptStatus::InProgress, $row->status, 'The finalization must not be rolled back.');
        $this->assertSame('auto_submit_at_deadline', $row->end_reason);
        // Grading records unanswered questions; the late selection itself is not stored.
        $answer = $row->answers()->where('question_id', $question->question_id)->first();
        $this->assertTrue($answer === null || ($answer->option_id === null && $answer->selectedOptions()->count() === 0));
    }

    public function test_a_submit_that_crosses_the_deadline_while_locking_finalizes(): void
    {
        // The copy the request holds still shows time left; the deadline has
        // passed by the time the submit holds the row lock, so the check on
        // the locked row finalizes the attempt as a deadline submission.
        [$stale, $row] = $this->attemptWhoseDeadlinePassedAfterTheCheck();
        $this->assertFalse($stale->isExpired());

        $result = app(SubmitExamAttemptAction::class)->execute($stale);

        $this->assertSame('auto_submit_at_deadline', $result->end_reason);
        $this->assertNotSame(ExamAttemptStatus::InProgress, $row->fresh()->status);
    }

    public function test_strict_expire_policy_keeps_the_expired_status_when_refusing(): void
    {
        [$stale, $row] = $this->attemptWhoseDeadlinePassedAfterTheCheck(['expiry_mode' => 'expire']);
        $question = $row->attemptQuestions()->first();
        $option = $question->attemptOptions()->first();

        $this->expectException(InvalidAttemptStateException::class);
        try {
            app(SaveExamAnswerAction::class)->execute($stale, $question->question_id, null, null, [$option->option_id]);
        } finally {
            $this->assertSame(ExamAttemptStatus::Expired, $row->fresh()->status);
        }
    }

    public function test_a_strict_policy_submit_that_crosses_the_deadline_stays_expired(): void
    {
        [$stale, $row] = $this->attemptWhoseDeadlinePassedAfterTheCheck(['expiry_mode' => 'expire']);
        $this->assertFalse($stale->isExpired());

        $this->expectException(InvalidAttemptStateException::class);
        try {
            app(SubmitExamAttemptAction::class)->execute($stale);
        } finally {
            $this->assertSame(ExamAttemptStatus::Expired, $row->fresh()->status, 'The refusal must not roll back the expiry.');
        }
    }
}
