<?php

namespace Tests\Feature\Exam;

use App\Actions\Exam\FinalizeExpiredAttemptAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P0.12 — Auto-submit at expires_at is deterministic, lossless, and idempotent.
 *
 * Policy under test (exams.expiry_mode):
 *   - 'auto_submit' (default): at the deadline the saved answers are submitted
 *     and graded server-side; submitted_at = expires_at (never job runtime);
 *     essays are preserved for manual grading; end_reason is auditable and
 *     user-explainable.
 *   - 'expire' (legacy strict): the blank-timeout behavior is preserved
 *     (status = expired, no score) and late submission is refused with 422.
 *   - Repeated finalization (scheduler + late submit + new attempt) never
 *     double-grades, never rewrites timestamps, never demotes published work.
 */
class AutoSubmitAtDeadlineTest extends ApiTestCase
{
    use InteractsWithExams;

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\ExamAttempt}
     */
    private function startedAttemptPastDeadline(array $examAttrs = [], ?array $answerMap = null): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'show_result_immediately' => true,
            'pass_percentage' => 60,
        ], $examAttrs));

        $this->addSingleChoiceQuestion($exam, ['points' => 1, 'question_text' => 'Q1']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1, 'question_text' => 'Q2']);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201);

        $attempt = ExamAttempt::findOrFail($startRes->json('data.id'));

        // Optional: answer [questionIndex => bool correct]
        if ($answerMap !== null) {
            foreach ($answerMap as $qIndex => $correct) {
                $aq = $attempt->attemptQuestions()->orderBy('id')->get()[$qIndex];
                $opt = $correct
                    ? $aq->attemptOptions()->where('is_correct', true)->first()
                    : $aq->attemptOptions()->where('is_correct', false)->first();

                $this->actingAs($student, 'sanctum')
                    ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                        'question_id' => $aq->question_id,
                        'option_id' => $opt->option_id,
                    ])->assertStatus(200);
            }
        }

        // The deadline has passed; the attempt was never submitted.
        $attempt->forceFill([
            'expires_at' => now()->subSeconds(30),
            'status' => ExamAttemptStatus::InProgress->value,
        ])->save();

        return [$student, $attempt->fresh(['attemptQuestions.attemptOptions', 'answers.selectedOptions', 'exam'])];
    }

    public function test_auto_submit_grades_saved_answers_and_sets_deadline_timestamp(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit'], [
            0 => true,
            1 => false,
        ]);

        $this->artisan('attempts:process-expired')->assertExitCode(0);

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::Submitted->value, $attempt->status->value);
        $this->assertSame('auto_submit_at_deadline', $attempt->end_reason);
        $this->assertTrue($attempt->submitted_at->equalTo($attempt->expires_at), 'submitted_at must be the deadline, not job runtime');
        $this->assertNotNull($attempt->scored_at);
        $this->assertSame(1, (int) $attempt->score);
        $this->assertEqualsWithDelta(50.0, (float) $attempt->raw_percentage, 0.0001);
        $this->assertSame('failed', $attempt->outcome()->value); // 50 < 60

        // Saved answers were graded, not discarded: both rows exist with scores.
        $answers = $attempt->answers()->get();
        $this->assertSame(2, $answers->count());
        $this->assertSame(1, (int) $answers->sum('points_earned'));
    }

    public function test_auto_submit_is_idempotent_across_repeated_runs(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit'], [0 => true, 1 => true]);

        $this->artisan('attempts:process-expired')->assertExitCode(0);
        $first = $attempt->fresh();

        // Scheduler tick + late submit + direct action — all repeat paths.
        $this->artisan('attempts:process-expired')->assertExitCode(0);
        app(FinalizeExpiredAttemptAction::class)->execute($attempt->fresh());
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $second = $attempt->fresh();
        $this->assertSame($first->status->value, $second->status->value);
        $this->assertSame($first->score, $second->score);
        $this->assertSame($first->percentage, $second->percentage);
        $this->assertTrue($first->submitted_at->equalTo($second->submitted_at));
        $this->assertTrue($first->scored_at->equalTo($second->scored_at), 'No double grading timestamp');
        $this->assertSame('auto_submit_at_deadline', $second->end_reason);
    }

    public function test_published_attempt_is_never_demoted_by_late_finalization(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit'], [0 => true, 1 => true]);

        $this->artisan('attempts:process-expired')->assertExitCode(0);

        // Simulate the rest of the lifecycle: grades published by staff.
        $attempt->refresh()->forceFill([
            'status' => ExamAttemptStatus::Published->value,
            'grades_published_at' => now(),
        ])->save();

        $this->artisan('attempts:process-expired')->assertExitCode(0);
        app(FinalizeExpiredAttemptAction::class)->execute($attempt->fresh());

        $this->assertSame(ExamAttemptStatus::Published->value, $attempt->fresh()->status->value);
    }

    public function test_essay_answers_are_preserved_pending_grading_at_auto_submit(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline([
            'expiry_mode' => 'auto_submit',
            'max_attempts' => 3,
        ], [0 => true, 1 => true]);

        // Add an essay question to the exam AFTER the first attempt started
        // (fresh attempts snapshot at start), then take a new attempt that
        // includes it.
        $essayAttemptStudent = $student;
        $exam = $attempt->exam;

        \App\Models\Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'essay',
            'points' => 5,
            'position' => 3,
        ]);

        $startRes = $this->actingAs($essayAttemptStudent, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true]);
        if ($startRes->status() === 422) {
            // Previous attempt already handed in via finalize — finish it first.
            $this->artisan('attempts:process-expired')->assertExitCode(0);
            $startRes = $this->actingAs($essayAttemptStudent, 'sanctum')
                ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true]);
        }
        $startRes->assertStatus(201);
        $essayAttempt = ExamAttempt::findOrFail($startRes->json('data.id'));

        $essayAq = $essayAttempt->attemptQuestions()
            ->where('question_type', 'essay')
            ->firstOrFail();

        $this->actingAs($essayAttemptStudent, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$essayAttempt->id}/answers", [
                'question_id' => $essayAq->question_id,
                'answer_text' => 'My long-form answer body.',
            ])->assertStatus(200);

        $essayAttempt->forceFill([
            'expires_at' => now()->subSeconds(10),
            'status' => ExamAttemptStatus::InProgress->value,
        ])->save();

        $this->artisan('attempts:process-expired')->assertExitCode(0);

        $essayAttempt->refresh();
        // Objective part graded, essay kept for manual review (status grading).
        $this->assertSame(ExamAttemptStatus::Grading->value, $essayAttempt->status->value);
        $this->assertSame('auto_submit_at_deadline', $essayAttempt->end_reason);
        $this->assertTrue($essayAttempt->submitted_at->equalTo($essayAttempt->expires_at));

        $essayAnswer = $essayAttempt->answers()->where('question_id', $essayAq->question_id)->firstOrFail();
        $this->assertSame('My long-form answer body.', $essayAnswer->answer_text);
        $this->assertNull($essayAnswer->is_correct, 'Essay must stay ungraded — no fabricated auto-grade');
    }

    public function test_expire_mode_keeps_legacy_blank_expired_attempt_and_rejects_late_submit(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline(['expiry_mode' => 'expire'], [0 => true]);

        // Late submit in strict expire mode is refused (documented 422).
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(422);

        $this->artisan('attempts:process-expired')->assertExitCode(0);

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::Expired->value, $attempt->status->value);
        $this->assertSame('expired', $attempt->end_reason);
        $this->assertNull($attempt->submitted_at);
        $this->assertNull($attempt->score);
        $this->assertSame('expired', $attempt->outcome()->value);
    }

    public function test_auto_submit_mode_late_submit_finalizes_with_saved_work(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit'], [0 => true, 1 => false]);

        // A submit that lands after the deadline must not lose the answers.
        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::Submitted->value, $attempt->status->value);
        $this->assertSame('auto_submit_at_deadline', $attempt->end_reason);
        $this->assertSame(1, (int) $attempt->score);
        $this->assertTrue($attempt->submitted_at->equalTo($attempt->expires_at));
    }

    public function test_starting_new_attempt_finalizes_stale_attempt_per_policy(): void
    {
        [$student, $attempt] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit', 'max_attempts' => 2], [0 => true, 1 => true]);

        // The stale in-progress attempt is past its deadline; starting a new
        // one must finalize it first instead of silently blocking or discarding.
        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$attempt->exam_id}/start", ['rules_acknowledged' => true]);
        $startRes->assertStatus(201);

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::Submitted->value, $attempt->status->value);
        $this->assertSame('auto_submit_at_deadline', $attempt->end_reason);
        $this->assertSame(2, (int) $attempt->score, 'Stale attempt keeps its graded saved answers');

        $newAttempt = ExamAttempt::findOrFail($startRes->json('data.id'));
        $this->assertNotSame($attempt->id, $newAttempt->id);
        $this->assertSame(ExamAttemptStatus::InProgress->value, $newAttempt->status->value);
    }

    public function test_command_bulk_processes_only_expired_attempts_and_is_idempotent(): void
    {
        [$studentA, $autoA] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit'], [0 => true, 1 => true]);
        [$studentB, $autoB] = $this->startedAttemptPastDeadline(['expiry_mode' => 'auto_submit'], [0 => false, 1 => false]);
        [$studentC, $expireC] = $this->startedAttemptPastDeadline(['expiry_mode' => 'expire'], [0 => true]);

        // One fresh (not expired) attempt must be left alone.
        [$studentD, $freshD] = $this->startedAttemptPastDeadline([], null);
        $freshD->forceFill(['expires_at' => now()->addMinutes(10)])->save();

        $this->artisan('attempts:process-expired', ['--limit' => 50])->assertExitCode(0);

        $this->assertSame(ExamAttemptStatus::Submitted->value, $autoA->fresh()->status->value);
        $this->assertSame(2, (int) $autoA->fresh()->score);
        $this->assertSame(0, (int) $autoB->fresh()->score);
        $this->assertSame(ExamAttemptStatus::Expired->value, $expireC->fresh()->status->value);
        $this->assertSame(ExamAttemptStatus::InProgress->value, $freshD->fresh()->status->value, 'Unexpired attempt must not be touched');

        // Second run changes nothing.
        $this->artisan('attempts:process-expired', ['--limit' => 50])->assertExitCode(0);
        $this->assertSame(ExamAttemptStatus::Submitted->value, $autoA->fresh()->status->value);
        $this->assertTrue($autoA->fresh()->submitted_at->equalTo($autoA->fresh()->expires_at));
        $this->assertSame(ExamAttemptStatus::Expired->value, $expireC->fresh()->status->value);
    }
}
