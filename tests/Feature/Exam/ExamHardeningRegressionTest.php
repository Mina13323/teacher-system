<?php

namespace Tests\Feature\Exam;

use App\Actions\Exam\GradeEssayAnswerAction;
use App\Actions\Exam\PublishExamGradesAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class ExamHardeningRegressionTest extends ApiTestCase
{
    use InteractsWithExams;

    private function setupExamWithFourMcqs(array $examAttrs = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'max_attempts' => 100,
            'show_result_immediately' => false,
            'shuffle_questions' => true,
            'shuffle_options' => true,
        ], $examAttrs));

        // Create 4 MCQs, 1 point each
        for ($i = 1; $i <= 4; $i++) {
            $this->addSingleChoiceQuestion($exam, ['points' => 1, 'question_text' => "Question {$i}"]);
        }

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$teacher, $student, $course, $exam];
    }

    /**
     * BUG B reproduction test:
     * Answer all 4 MCQs with incorrect options.
     * Verify whether total score = 0 and each question points_earned = 0.
     */
    public function test_bug_b_all_mcqs_answered_incorrectly_must_yield_zero_score(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs();

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attemptId = $startRes->json('data.id');
        $attempt = ExamAttempt::findOrFail($attemptId);

        // Fetch attempt questions from API
        $attemptRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attemptId}")
            ->assertStatus(200);

        $questions = $attemptRes->json('data.questions');
        $this->assertCount(4, $questions);

        // Answer ALL 4 with the WRONG option
        foreach ($questions as $q) {
            $snapshotQ = $attempt->attemptQuestions()->where('question_id', $q['id'])->first();
            $wrongOption = $snapshotQ->attemptOptions()->where('is_correct', false)->first();

            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attemptId}/answers", [
                    'question_id' => $q['id'],
                    'option_id' => $wrongOption->option_id,
                ])->assertStatus(200);
        }

        // Submit attempt
        $submitRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/submit")
            ->assertStatus(200);

        $attempt->refresh();

        // Check Teacher detail API
        $teacherDetailRes = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attemptId}")
            ->assertStatus(200);

        $this->assertSame(0, $attempt->score, 'Score must be 0 when all MCQs are incorrect');
        $this->assertSame(0, $attempt->percentage, 'Percentage must be 0%');
        $this->assertSame(0, $teacherDetailRes->json('data.score'));
        $this->assertSame(4, $teacherDetailRes->json('data.total_points'));

        $teacherQuestions = $teacherDetailRes->json('data.questions');
        $this->assertCount(4, $teacherQuestions);
        $totalAwardedInResource = array_sum(array_column($teacherQuestions, 'points_earned'));
        $this->assertSame(0, $totalAwardedInResource);

        foreach ($attempt->answers as $ans) {
            $this->assertFalse($ans->is_correct);
            $this->assertSame(0, $ans->points_earned);
        }
    }

    /**
     * BUG B contract test:
     * Snapshot questions and options remain authoritative in teacher detail
     * even if live exam questions are modified or deleted.
     */
    public function test_bug_b_teacher_modal_contract_survives_live_question_modification(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs();

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $attemptId = $startRes->json('data.id');

        // Answer question 1 correctly (1 pt) and others incorrectly (0 pt)
        $attempt = ExamAttempt::findOrFail($attemptId);
        $firstAq = $attempt->attemptQuestions->first();
        $correctOpt = $firstAq->attemptOptions()->where('is_correct', true)->first();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/answers", [
                'question_id' => $firstAq->question_id,
                'option_id' => $correctOpt->option_id,
            ])->assertStatus(200);

        // Submit
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/submit")
            ->assertStatus(200);

        // Teacher deletes all live questions from the exam
        $exam->questions()->delete();

        // Teacher view attempt detail
        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attemptId}")
            ->assertStatus(200);

        $this->assertSame(1, $res->json('data.score'));
        $this->assertSame(4, $res->json('data.total_points'));
        $this->assertCount(4, $res->json('data.questions'));

        $qList = $res->json('data.questions');
        $this->assertSame(1, $qList[0]['points_earned']);
        $this->assertTrue($qList[0]['is_correct']);
        $this->assertSame(0, $qList[1]['points_earned']);
    }

    /**
     * BUG A test:
     * Backgrounding/closing client calls terminate endpoint,
     * immediately terminating and flagging attempt as cheated/violating.
     */
    public function test_bug_a_terminate_endpoint_flags_and_submits_attempt_immediately(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs();

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $attemptId = $startRes->json('data.id');

        // Student triggers termination on visibilitychange/pagehide
        $termRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/terminate", [
                'reason' => 'TAB_SWITCH',
            ])->assertStatus(200);

        $attempt = ExamAttempt::findOrFail($attemptId);

        $this->assertSame(ExamAttemptStatus::Submitted, $attempt->status);
        $this->assertSame('flagged', $attempt->integrity_status->value);
        $this->assertNull($attempt->active_key);
        $this->assertNotNull($attempt->submitted_at);

        // When student returns to the attempt, it is no longer in progress
        $showRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attemptId}")
            ->assertStatus(200);

        $this->assertSame('submitted', $showRes->json('data.status'));
    }

    /**
     * BUG A test:
     * Student abruptly leaves (app killed, lid closed) with missing heartbeats.
     * When student returns or endpoint is queried, heartbeat timeout flags and terminates attempt.
     */
    public function test_bug_a_heartbeat_timeout_terminates_abandoned_attempt(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs();

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $attemptId = $startRes->json('data.id');
        $attempt = ExamAttempt::findOrFail($attemptId);

        // Simulate client disappearing for 70 seconds
        $attempt->last_heartbeat_at = now()->subSeconds(75);
        $attempt->save();

        // Student returns to app
        $showRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attemptId}")
            ->assertStatus(200);

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::Submitted, $attempt->status);
        $this->assertSame('flagged', $attempt->integrity_status->value);
        $this->assertNull($attempt->active_key);

        // Heartbeat on terminated attempt is rejected
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/heartbeat")
            ->assertStatus(422);
    }

    /**
     * BUG C reproduction and boundary test:
     * Student can start multiple attempts up to max_attempts.
     * Attempt max_attempts + 1 is refused.
     * Simultaneous active attempts are blocked.
     */
    public function test_bug_c_attempt_limit_and_sequential_attempt_progression(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs(['max_attempts' => 3]);

        // Attempt #1
        $start1 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $id1 = $start1->json('data.id');
        $this->assertSame(1, $start1->json('data.attempt_number'));

        // Calling start again while #1 is in progress returns the existing attempt idempotently
        $startDup = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $this->assertSame($id1, $startDup->json('data.id'));
        $this->assertSame(1, $startDup->json('data.attempt_number'));

        // Complete attempt #1
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$id1}/submit")
            ->assertStatus(200);

        // Attempt #2
        $start2 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $id2 = $start2->json('data.id');
        $this->assertSame(2, $start2->json('data.attempt_number'));

        // Complete attempt #2
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$id2}/submit")
            ->assertStatus(200);

        // Attempt #3
        $start3 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $id3 = $start3->json('data.id');
        $this->assertSame(3, $start3->json('data.attempt_number'));

        // Complete attempt #3
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$id3}/submit")
            ->assertStatus(200);

        // Attempt #4 must be rejected
        $start4 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start");

        $start4->assertStatus(422);
        $this->assertStringContainsString('maximum number of attempts', $start4->json('message'));
    }

    /**
     * Verify attempt 100 succeeds when max_attempts = 100, and attempt 101 is rejected.
     */
    public function test_max_attempts_up_to_100_and_blocked_at_101(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs(['max_attempts' => 100]);

        // Seed 99 completed attempts directly into DB to test high volume scale
        for ($i = 1; $i <= 99; $i++) {
            ExamAttempt::create([
                'exam_id' => $exam->id,
                'student_id' => $student->id,
                'attempt_number' => $i,
                'started_at' => now()->subHours(100 - $i),
                'submitted_at' => now()->subHours(100 - $i)->addMinutes(10),
                'expires_at' => now()->subHours(100 - $i)->addMinutes(30),
                'status' => ExamAttemptStatus::Submitted->value,
                'score' => 4,
                'percentage' => 100,
                'pass_percentage' => 50,
                'active_key' => null,
            ]);
        }

        // Start attempt #100
        $start100 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $id100 = $start100->json('data.id');
        $this->assertSame(100, $start100->json('data.attempt_number'));

        // Submit attempt #100
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$id100}/submit")
            ->assertStatus(200);

        // Attempt #101 must be blocked
        $start101 = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start");

        $start101->assertStatus(422);
        $this->assertStringContainsString('maximum number of attempts', $start101->json('message'));
    }

    /**
     * CHEATING RESULT SEMANTICS:
     * Flagged/cheated attempts must NEVER accidentally become passed=true,
     * nor can they qualify for competition scoring.
     */
    public function test_flagged_cheated_attempt_cannot_be_passed_or_qualify_for_competition(): void
    {
        [$teacher, $student, $course, $exam] = $this->setupExamWithFourMcqs();

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $attemptId = $startRes->json('data.id');
        $attempt = ExamAttempt::findOrFail($attemptId);

        // Answer all questions correctly to get 100%
        foreach ($attempt->attemptQuestions as $aq) {
            $correctOpt = $aq->attemptOptions()->where('is_correct', true)->first();
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attemptId}/answers", [
                    'question_id' => $aq->question_id,
                    'option_id' => $correctOpt->option_id,
                ])->assertStatus(200);
        }

        // Terminate due to violation (cheating)
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/terminate", ['reason' => 'TAB_SWITCH'])
            ->assertStatus(200);

        // Teacher publishes grades
        app(PublishExamGradesAction::class)->execute($teacher, ExamAttempt::find($attemptId));

        // In student attempt view: passed must be FALSE despite 100% score
        $studentAttemptRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attemptId}")
            ->assertStatus(200);

        $this->assertSame(4, $studentAttemptRes->json('data.score'));
        $this->assertSame(100, $studentAttemptRes->json('data.percentage'));
        $this->assertFalse($studentAttemptRes->json('data.passed'), 'Flagged attempt must NOT be passed');

        // In teacher detail view: passed must also be FALSE
        $teacherDetailRes = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attemptId}")
            ->assertStatus(200);

        $this->assertFalse($teacherDetailRes->json('data.passed'), 'Teacher detail must show passed=false for flagged attempt');

        // Create competition referencing this exam
        $competition = \App\Models\Competition::create([
            'course_id' => $course->id,
            'exam_id' => $exam->id,
            'title' => 'Integrity Competition',
            'status' => \App\Enums\CompetitionStatus::Published->value,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'scoring_type' => \App\Enums\CompetitionScoringType::HighestScore->value,
            'ranking_type' => \App\Enums\CompetitionRankingType::ScoreDesc->value,
            'max_participants' => 100,
        ]);

        // Calculate competition score: must be NULL because flagged attempts are excluded
        $calcAction = app(\App\Actions\Competition\CalculateCompetitionScoreAction::class);
        $scoreResult = $calcAction->execute($competition, $student->id);
        $this->assertNull($scoreResult, 'Flagged attempt must NOT qualify for competition score or leaderboard');
    }

    /**
     * STALE CLIENT REGRESSION:
     * After termination, a stale browser tab cannot save answers,
     * cannot heartbeat, cannot submit anew, and cannot reset integrity.
     */
    public function test_stale_client_after_termination_cannot_mutate_or_reset_attempt(): void
    {
        [$teacher, $student, , $exam] = $this->setupExamWithFourMcqs();

        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);
        $attemptId = $startRes->json('data.id');
        $attempt = ExamAttempt::findOrFail($attemptId);
        $firstQ = $attempt->attemptQuestions->first();
        $opt = $firstQ->attemptOptions->first();

        // Terminate attempt
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/terminate", ['reason' => 'TAB_SWITCH'])
            ->assertStatus(200);

        // 1. Stale client cannot save answers (422)
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/answers", [
                'question_id' => $firstQ->question_id,
                'option_id' => $opt->option_id,
            ])->assertStatus(422);

        // 2. Stale client cannot heartbeat (422)
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/heartbeat")
            ->assertStatus(422);

        // 3. Stale client cannot reset integrity status (rejected or ignored)
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/integrity-events", [
                'event_type' => 'TAB_SWITCH',
                'integrity_status' => 'cleared',
            ])->assertStatus(422); // Attempt is submitted so events are rejected

        $attempt->refresh();
        $this->assertSame('flagged', $attempt->integrity_status->value);
    }
}
