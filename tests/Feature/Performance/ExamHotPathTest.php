<?php

namespace Tests\Feature\Performance;

use App\Actions\Exam\SubmitExamAttemptAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * The exam hot paths (submit, answer save, heartbeat, integrity events,
 * result) were rewritten to use fewer statements. These tests pin the
 * behavior they must keep and a statement budget per request, so a later
 * change cannot quietly bring the per-question writes or the N+1 back.
 *
 * Budgets count every statement of the request with the test cache stores
 * (array), which matches production once the rate limiter and permission
 * cache are on the file store.
 */
class ExamHotPathTest extends ApiTestCase
{
    use InteractsWithExams;

    private User $teacher;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
        $this->student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$this->course->id}/enroll")
            ->assertStatus(201);
    }

    public function studentUser(): User
    {
        return $this->student;
    }

    private function exam(int $singles, int $multis = 0, array $attributes = []): Exam
    {
        $exam = $this->makeExam($this->teacher, $this->course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'max_attempts' => 5,
            'show_result_immediately' => true,
        ], $attributes));

        for ($i = 0; $i < $singles; $i++) {
            $this->addSingleChoiceQuestion($exam);
        }
        for ($i = 0; $i < $multis; $i++) {
            $this->addMultipleChoiceQuestion($exam);
        }

        return $exam;
    }

    private function start(Exam $exam): array
    {
        return $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->json('data');
    }

    /** @return array{0: mixed, 1: int} the callback result and the statements it ran */
    private function counting(callable $fn): array
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->app['auth']->forgetGuards();
        $result = $fn();

        return [$result, $count];
    }

    private function questionsByType(array $attempt, string $type): array
    {
        return array_values(array_filter($attempt['questions'], fn ($q) => $q['question_type'] === $type));
    }

    private function correctIds(array $question): array
    {
        return Question::findOrFail($question['id'])->options()->where('is_correct', true)->orderBy('id')->pluck('id')->all();
    }

    private function wrongIds(array $question): array
    {
        return Question::findOrFail($question['id'])->options()->where('is_correct', false)->orderBy('id')->pluck('id')->all();
    }

    // ------------------------------------------------------------------
    // Submit
    // ------------------------------------------------------------------

    public function test_submit_grades_every_kind_of_choice_answer_and_writes_zero_rows(): void
    {
        $exam = $this->exam(3, 2);
        $attempt = $this->start($exam);
        $singles = $this->questionsByType($attempt, 'single_choice');
        $multis = $this->questionsByType($attempt, 'multiple_choice');
        $api = $this->actingAs($this->student, 'sanctum');

        // single correct, single wrong, single unanswered;
        // multi exact (correct), multi partial (wrong).
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $singles[0]['id'], 'option_ids' => [$this->correctIds($singles[0])[0]]])->assertOk();
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $singles[1]['id'], 'option_ids' => [$this->wrongIds($singles[1])[0]]])->assertOk();
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $multis[0]['id'], 'option_ids' => $this->correctIds($multis[0])])->assertOk();
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $multis[1]['id'], 'option_ids' => [$this->correctIds($multis[1])[0]]])->assertOk();

        $res = $api->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        $rows = ExamAnswer::where('attempt_id', $attempt['id'])->get()->keyBy('question_id');
        $this->assertCount(5, $rows, 'Every choice question has a graded row, unanswered ones included.');
        $this->assertTrue($rows[$singles[0]['id']]->is_correct);
        $this->assertSame(1, $rows[$singles[0]['id']]->points_earned);
        $this->assertFalse($rows[$singles[1]['id']]->is_correct);
        $this->assertSame(0, $rows[$singles[1]['id']]->points_earned);
        $this->assertFalse($rows[$singles[2]['id']]->is_correct);
        $this->assertSame(0, $rows[$singles[2]['id']]->points_earned);
        $this->assertNull($rows[$singles[2]['id']]->option_id);
        $this->assertTrue($rows[$multis[0]['id']]->is_correct);
        $this->assertSame(2, $rows[$multis[0]['id']]->points_earned);
        $this->assertFalse($rows[$multis[1]['id']]->is_correct);
        $this->assertSame(0, $rows[$multis[1]['id']]->points_earned);
        // The selections themselves are untouched by grading.
        $this->assertSame($this->correctIds($multis[0]), $rows[$multis[0]['id']]->selectedOptionIds());

        $row = ExamAttempt::findOrFail($attempt['id']);
        // 1 + 2 of 3 + 1 + 2 + 2 = 7 points; 3 earned.
        $this->assertSame(3, $row->score);
        $this->assertSame(43, $row->percentage);
        $this->assertEqualsWithDelta(42.857, $row->raw_percentage, 0.0005);
        $this->assertSame(ExamAttemptStatus::Submitted, $row->status);
        $this->assertNull($row->active_key);
        $this->assertNotNull($row->scored_at);
        $this->assertNotNull($row->grades_published_at);
        $this->assertSame('submitted_by_student', $row->end_reason);
        $res->assertJsonPath('data.score', 3)->assertJsonPath('data.percentage', 43)->assertJsonPath('data.grades_published', true);
    }

    public function test_submit_statements_do_not_grow_with_the_number_of_questions(): void
    {
        $budgets = [];
        foreach ([5, 40] as $n) {
            $exam = $this->exam($n);
            $attempt = $this->start($exam);
            $q = $attempt['questions'][0];
            $this->actingAs($this->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $q['id'], 'option_ids' => [$q['options'][0]['id']]])
                ->assertOk();

            [, $budgets[$n]] = $this->counting(fn () => $this->actingAs($this->student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")
                ->assertOk());

            $this->assertSame($n, ExamAnswer::where('attempt_id', $attempt['id'])->count());
        }

        $this->assertSame($budgets[5], $budgets[40], 'Submit must not write one statement per question.');
        $this->assertLessThanOrEqual(12, $budgets[40]);
    }

    public function test_a_duplicate_submit_returns_the_first_result_without_regrading_or_notifying_again(): void
    {
        Notification::fake();
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        $api = $this->actingAs($this->student, 'sanctum');

        $first = $api->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk()->json('data');
        $scoredAt = ExamAttempt::findOrFail($attempt['id'])->scored_at;
        $this->travel(5)->seconds();
        $second = $api->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk()->json('data');

        $this->assertSame($first['score'], $second['score']);
        $this->assertSame($first['submitted_at'], $second['submitted_at']);
        $this->assertEquals($scoredAt, ExamAttempt::findOrFail($attempt['id'])->scored_at);
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);
    }

    public function test_the_result_notification_reaches_the_owner_only(): void
    {
        Notification::fake();
        $exam = $this->exam(1);
        $attempt = $this->start($exam);

        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        Notification::assertSentTo($this->student, ResultAvailableNotification::class,
            fn ($n) => $n->attempt->id === $attempt['id']);
    }

    public function test_an_essay_keeps_its_answer_and_sends_the_attempt_to_grading_unpublished(): void
    {
        Notification::fake();
        $exam = $this->exam(1);
        $essay = Question::factory()->create(['exam_id' => $exam->id, 'type' => 'essay', 'points' => 5, 'position' => 2]);
        $attempt = $this->start($exam);
        $api = $this->actingAs($this->student, 'sanctum');
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $essay->id, 'answer_text' => 'My essay.'])->assertOk();

        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk()
            ->assertJsonPath('data.status', ExamAttemptStatus::Grading->value)
            ->assertJsonPath('data.grades_published', false);

        $essayRow = ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay->id)->firstOrFail();
        $this->assertSame('My essay.', $essayRow->answer_text);
        $this->assertNull($essayRow->points_earned, 'Essays are never auto-graded.');
        $this->assertNull($essayRow->is_correct);
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertNull($row->grades_published_at);
        $this->assertNull($row->active_key);
        Notification::assertNothingSent();
    }

    public function test_a_submit_after_the_deadline_is_graded_as_a_deadline_submission_and_audited(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        $q = $attempt['questions'][0];
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $q['id'], 'option_ids' => [$this->correctIds($q)[0]]])
            ->assertOk();
        $expiresAt = ExamAttempt::findOrFail($attempt['id'])->expires_at;

        $this->travelTo($expiresAt->copy()->addSeconds(40));
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame('auto_submit_at_deadline', $row->end_reason);
        $this->assertEquals($expiresAt, $row->submitted_at, 'A late request never moves the submission time.');
        $this->assertSame(1, $row->score);
        $this->assertSame(2, ExamAnswer::where('attempt_id', $attempt['id'])->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attempt.auto_submit', 'target_id' => $attempt['id']]);
    }

    public function test_an_answer_that_raced_the_submit_cannot_change_the_graded_attempt(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        $api = $this->actingAs($this->student, 'sanctum');
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        $q = $attempt['questions'][0];
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $q['id'], 'option_ids' => [$q['options'][0]['id']]])
            ->assertStatus(422);
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $q['id'], 'option_ids' => [$q['options'][0]['id']], 'compact_response' => true])
            ->assertStatus(422);

        $this->assertSame(0, ExamAttempt::findOrFail($attempt['id'])->score);
        $this->assertNull(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $q['id'])->value('option_id'));
    }

    public function test_the_sweep_and_a_submit_finalize_an_attempt_only_once(): void
    {
        Notification::fake();
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        $this->travelTo(ExamAttempt::findOrFail($attempt['id'])->expires_at->copy()->addSeconds(5));

        $this->artisan('attempts:process-expired')->assertSuccessful();
        $afterSweep = ExamAttempt::findOrFail($attempt['id']);
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertEquals($afterSweep->scored_at, $row->scored_at);
        $this->assertSame(2, ExamAnswer::where('attempt_id', $attempt['id'])->count());
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);
    }

    // ------------------------------------------------------------------
    // Answer save
    // ------------------------------------------------------------------

    public function test_a_compact_answer_acknowledgement_reports_exactly_what_was_stored(): void
    {
        $exam = $this->exam(1, 1);
        Question::query()->where('exam_id', $exam->id)->update(['explanation_enabled' => true]);
        $attempt = $this->start($exam);
        $multi = $this->questionsByType($attempt, 'multiple_choice')[0];
        $ids = $this->correctIds($multi);
        $api = $this->actingAs($this->student, 'sanctum');
        $url = "/api/v1/student/attempts/{$attempt['id']}/answers";

        $ack = $api->postJson($url, ['question_id' => $multi['id'], 'option_ids' => array_reverse($ids), 'explanation' => 'Because.', 'compact_response' => true])
            ->assertOk()->json('data');
        $this->assertSame(['id', 'status', 'expires_at', 'question'], array_keys($ack));
        $this->assertSame($attempt['id'], $ack['id']);
        $this->assertSame('in_progress', $ack['status']);
        $this->assertSame(['id' => $multi['id'], 'selected_option_id' => null, 'selected_option_ids' => $ids, 'answer_text' => null, 'explanation' => 'Because.'], $ack['question']);

        // An explanation not sent again is kept, as before.
        $ack = $api->postJson($url, ['question_id' => $multi['id'], 'option_ids' => [$ids[0]], 'compact_response' => true])->assertOk()->json('data');
        $this->assertSame(['id' => $multi['id'], 'selected_option_id' => $ids[0], 'selected_option_ids' => [$ids[0]], 'answer_text' => null, 'explanation' => 'Because.'], $ack['question']);

        // The full attempt read agrees with the acknowledgement.
        $full = collect($api->getJson("/api/v1/student/attempts/{$attempt['id']}")->assertOk()->json('data.questions'))->firstWhere('id', $multi['id']);
        $this->assertSame($ack['question']['selected_option_ids'], $full['selected_option_ids']);
        $this->assertSame($ack['question']['selected_option_id'], $full['selected_option_id']);
        $this->assertSame($ack['question']['explanation'], $full['explanation']);

        // Clearing the selection deletes the answer.
        $ack = $api->postJson($url, ['question_id' => $multi['id'], 'option_ids' => [], 'compact_response' => true])->assertOk()->json('data');
        $this->assertSame([], $ack['question']['selected_option_ids']);
        $this->assertNull($ack['question']['selected_option_id']);
        $this->assertFalse(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $multi['id'])->exists());
    }

    public function test_a_compact_essay_acknowledgement_carries_the_text(): void
    {
        $exam = $this->exam(1);
        $essay = Question::factory()->create(['exam_id' => $exam->id, 'type' => 'essay', 'points' => 5, 'position' => 2]);
        $attempt = $this->start($exam);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $essay->id, 'answer_text' => 'Text.', 'compact_response' => true])
            ->assertOk()
            ->assertJsonPath('data.question.answer_text', 'Text.')
            ->assertJsonPath('data.question.selected_option_ids', []);
    }

    public function test_the_full_answer_response_is_unchanged_without_the_flag(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        $q = $attempt['questions'][0];

        $res = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $q['id'], 'option_ids' => [$q['options'][1]['id']]])
            ->assertOk();

        $this->assertCount(2, $res->json('data.questions'));
        $this->assertSame([$q['options'][1]['id']], $res->json('data.questions.0.selected_option_ids'));
        $this->assertNotNull($res->json('data.integrity_rules'));
    }

    public function test_answer_validation_still_rejects_foreign_options_and_foreign_attempts(): void
    {
        $exam = $this->exam(2);
        $attempt = $this->start($exam);
        [$q1, $q2] = $attempt['questions'];
        $url = "/api/v1/student/attempts/{$attempt['id']}/answers";

        $this->actingAs($this->student, 'sanctum')
            ->postJson($url, ['question_id' => $q1['id'], 'option_ids' => [$q2['options'][0]['id']], 'compact_response' => true])
            ->assertStatus(422);
        $this->actingAs($this->student, 'sanctum')
            ->postJson($url, ['question_id' => $q1['id'], 'option_ids' => [$q1['options'][0]['id'], $q1['options'][1]['id']], 'compact_response' => true])
            ->assertStatus(422);

        $other = $this->createUserWithRole(UserRole::Student);
        $this->app['auth']->forgetGuards();
        $this->actingAs($other, 'sanctum')
            ->postJson($url, ['question_id' => $q1['id'], 'option_ids' => [$q1['options'][0]['id']], 'compact_response' => true])
            ->assertStatus(403);

        $this->assertFalse(ExamAnswer::where('attempt_id', $attempt['id'])->exists());
    }

    public function test_a_compact_answer_save_stays_within_its_statement_budget(): void
    {
        $exam = $this->exam(20);
        $attempt = $this->start($exam);
        $q = $attempt['questions'][0];
        $url = "/api/v1/student/attempts/{$attempt['id']}/answers";

        [, $first] = $this->counting(fn () => $this->actingAs($this->student, 'sanctum')
            ->postJson($url, ['question_id' => $q['id'], 'option_ids' => [$q['options'][0]['id']], 'compact_response' => true])->assertOk());
        [, $change] = $this->counting(fn () => $this->actingAs($this->student, 'sanctum')
            ->postJson($url, ['question_id' => $q['id'], 'option_ids' => [$q['options'][1]['id']], 'compact_response' => true])->assertOk());

        $this->assertLessThanOrEqual(9, $first);
        $this->assertLessThanOrEqual(9, $change);
    }

    // ------------------------------------------------------------------
    // Heartbeat
    // ------------------------------------------------------------------

    public function test_a_heartbeat_records_presence_in_one_update(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        // MySQL reports changed rows only: a beat in the same second as the
        // start changes nothing and takes the (also correct) full path.
        $this->travel(2)->seconds();

        [$res, $count] = $this->counting(fn () => $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertOk());

        $this->assertLessThanOrEqual(3, $count);
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertNotNull($row->last_heartbeat_at);
        $res->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.expires_at', $row->expires_at->toISOString());
        // The response carries the time with microseconds (as before); the
        // column stores whole seconds.
        $this->assertSame($row->last_heartbeat_at->format('Y-m-d\\TH:i:s'), substr($res->json('data.last_heartbeat_at'), 0, 19));
        $this->assertIsInt($res->json('data.server_time_ms'));
    }

    public function test_a_heartbeat_after_the_deadline_finalizes_and_is_refused(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $this->travelTo(ExamAttempt::findOrFail($attempt['id'])->expires_at->copy()->addSeconds(2));

        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertStatus(422);

        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame('auto_submit_at_deadline', $row->end_reason);
        $this->assertNotSame(ExamAttemptStatus::InProgress, $row->status);
    }

    public function test_a_heartbeat_in_the_deadline_second_takes_the_exact_path(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $expiresAt = ExamAttempt::findOrFail($attempt['id'])->expires_at;

        // Half a second into the deadline second: isExpired() says expired.
        $this->travelTo($expiresAt->copy()->addMilliseconds(500));
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertStatus(422);
        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($attempt['id'])->status);
    }

    public function test_a_heartbeat_after_the_exam_window_closed_finalizes(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        // The teacher closed the window; the attempt's own deadline is later.
        $exam->forceFill(['ends_at' => now()->subSecond()])->saveQuietly();

        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertStatus(422);
        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($attempt['id'])->status);
    }

    public function test_a_heartbeat_after_a_deleted_exams_window_closed_finalizes(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        // The attempt's exam relation includes deleted exams, so their window
        // still ends the attempt: the fast path must not accept it.
        $exam->forceFill(['ends_at' => now()->subSecond()])->saveQuietly();
        $exam->delete();

        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertStatus(422);
        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($attempt['id'])->status);
    }

    public function test_a_heartbeat_never_revives_a_finalized_attempt(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $api = $this->actingAs($this->student, 'sanctum');
        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();
        $before = ExamAttempt::findOrFail($attempt['id']);

        $api->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertStatus(422);

        $after = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame($before->status, $after->status);
        $this->assertEquals($before->last_heartbeat_at, $after->last_heartbeat_at);
    }

    public function test_another_student_cannot_send_a_heartbeat(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $other = $this->createUserWithRole(UserRole::Student);
        $before = ExamAttempt::findOrFail($attempt['id'])->last_heartbeat_at;
        $this->travel(30)->seconds();
        $this->app['auth']->forgetGuards();

        $this->actingAs($other, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/heartbeat")->assertStatus(403);
        $this->assertEquals($before, ExamAttempt::findOrFail($attempt['id'])->last_heartbeat_at);
    }

    // ------------------------------------------------------------------
    // Integrity events
    // ------------------------------------------------------------------

    public function test_integrity_events_keep_counts_scores_and_dedupe(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $url = "/api/v1/student/attempts/{$attempt['id']}/integrity-events";
        $api = new class($this)
        {
            public function __construct(private $test) {}

            public function postJson(string $url, array $data)
            {
                return $this->test->actingAs($this->test->studentUser(), 'sanctum')->postJson($url, $data);
            }
        };
        $points = fn (string $type) => (int) app(\App\Services\Integrity\IntegrityRiskConfig::class)
            ->riskPoints(\App\Enums\IntegrityEventType::from($type));

        [$res, $counted] = $this->counting(fn () => $api->postJson($url, ['event_type' => 'TAB_SWITCH'])->assertStatus(201));
        $res->assertJsonPath('data.recorded', true)->assertJsonPath('data.counted', true)->assertJsonPath('data.warning_count', 1);
        $this->assertLessThanOrEqual(10, $counted);

        // Same departure within the window: deduplicated, nothing written.
        [$res, $dup] = $this->counting(fn () => $api->postJson($url, ['event_type' => 'WINDOW_BLUR'])->assertStatus(201));
        $res->assertJsonPath('data.recorded', false)->assertJsonPath('data.deduplicated', true)->assertJsonPath('data.warning_count', 1);
        $this->assertLessThanOrEqual(5, $dup);

        // A return to the tab carries no risk and is not a warning.
        $api->postJson($url, ['event_type' => 'WINDOW_FOCUS'])->assertStatus(201)
            ->assertJsonPath('data.recorded', true)->assertJsonPath('data.counted', false)->assertJsonPath('data.warning_count', 1);

        $this->travel(10)->seconds();
        $api->postJson($url, ['event_type' => 'COPY_ATTEMPT'])->assertStatus(201);

        $row = ExamAttempt::findOrFail($attempt['id']);
        $expected = (int) $row->integrityEvents()->sum('risk_points');
        $this->assertSame($points('TAB_SWITCH') + $points('WINDOW_FOCUS') + $row->integrityEvents()->where('event_type', 'COPY_ATTEMPT')->sum('risk_points'), $expected);
        $this->assertSame($expected, (int) $row->risk_score);
        $this->assertSame(app(\App\Services\Integrity\IntegrityRiskConfig::class)->statusFor($expected), $row->integrity_status);
        $this->assertSame(3, $row->integrityEvents()->count());
    }

    public function test_risk_never_overrides_a_teacher_review_decision(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        ExamAttempt::whereKey($attempt['id'])->update(['integrity_status' => IntegrityStatus::Cleared->value]);

        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/integrity-events", ['event_type' => 'TAB_SWITCH'])
            ->assertStatus(201);

        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame(IntegrityStatus::Cleared, $row->integrity_status);
        $this->assertGreaterThan(0, (int) $row->risk_score);
    }

    public function test_exceeding_the_warning_threshold_still_terminates(): void
    {
        $exam = $this->exam(1);
        $attempt = $this->start($exam);
        $threshold = (int) collect([$attempt['integrity_rules']['violation_warning_threshold'] ?? null, config('integrity.warning_threshold', 5)])->filter()->first();
        $url = "/api/v1/student/attempts/{$attempt['id']}/integrity-events";

        $last = null;
        for ($i = 0; $i <= $threshold; $i++) {
            $this->travel(10)->seconds();
            $last = $this->actingAs($this->student, 'sanctum')->postJson($url, ['event_type' => 'TAB_SWITCH'])->assertStatus(201);
        }

        $last->assertJsonPath('data.terminated', true);
        $row = ExamAttempt::findOrFail($attempt['id']);
        $this->assertSame('integrity_threshold', $row->end_reason);
        $this->assertSame(IntegrityStatus::Flagged, $row->integrity_status);
    }

    // ------------------------------------------------------------------
    // Result
    // ------------------------------------------------------------------

    public function test_the_published_result_reads_reference_answers_without_a_query_per_question(): void
    {
        $budgets = [];
        foreach ([3, 30] as $n) {
            $exam = $this->exam($n, 0, ['allow_answer_review' => true]);
            Question::query()->where('exam_id', $exam->id)->update(['reference_answer' => 'Reference']);
            $attempt = $this->start($exam);
            $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

            [$res, $budgets[$n]] = $this->counting(fn () => $this->actingAs($this->student, 'sanctum')
                ->getJson("/api/v1/student/attempts/{$attempt['id']}")->assertOk());

            $this->assertSame('Reference', $res->json('data.questions.0.review.explanation'));
            $this->assertCount($n, $res->json('data.questions'));
        }

        $this->assertSame($budgets[3], $budgets[30]);
    }

    public function test_an_unpublished_result_still_exposes_no_review(): void
    {
        $exam = $this->exam(2, 0, ['show_result_immediately' => false]);
        $attempt = $this->start($exam);
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        $res = $this->actingAs($this->student, 'sanctum')->getJson("/api/v1/student/attempts/{$attempt['id']}")->assertOk();
        $this->assertNull($res->json('data.questions.0.review'));
        $this->assertNull($res->json('data.questions.0.options.0.is_correct'));
        $this->assertArrayNotHasKey('score', $res->json('data'));
    }

    public function test_the_submit_action_is_idempotent_when_called_directly(): void
    {
        $exam = $this->exam(1);
        $attempt = ExamAttempt::findOrFail($this->start($exam)['id']);

        $first = app(SubmitExamAttemptAction::class)->execute($attempt);
        $second = app(SubmitExamAttemptAction::class)->execute($attempt);

        $this->assertSame($first->status, $second->status);
        $this->assertEquals($first->scored_at, $second->scored_at);
    }
}
