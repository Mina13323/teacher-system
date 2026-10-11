<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\BuildsEssayAttempts;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1-C: the grading workspace saves one essay grade per request and gets back
 * only that answer plus the attempt summary; saves carry the grade version the
 * grader loaded, and a stale version is a 409 conflict that writes nothing.
 */
class EssayGradeSaveTest extends ApiTestCase
{
    use BuildsEssayAttempts, InteractsWithExams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEssayFixture();
    }

    private function handedInEssayAttempt(int $essays = 1): array
    {
        $attempt = $this->start($this->exam($essays));
        foreach ($this->essayIds($attempt) as $i => $id) {
            if ($i === 0) {
                $this->writeEssay($attempt, $id, "Line one\n  indented line two");
            }
        }
        $this->submit($attempt);

        return $attempt;
    }

    private function versionOf(array $attempt, int $questionId): string
    {
        return collect($this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt['id']}")
            ->json('data.questions'))->firstWhere('id', $questionId)['grade_version'];
    }

    private function save(array $attempt, int $questionId, int $points, ?string $version, ?string $feedback = 'Good.', $as = null)
    {
        $payload = [
            'question_id' => $questionId,
            'awarded_points' => $points,
            'feedback' => $feedback,
            'compact_response' => true,
        ];
        if ($version !== null) {
            $payload['expected_version'] = $version;
        }

        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt['id']}/grade-essay", $payload);
    }

    public function test_the_compact_response_carries_only_the_saved_answer_and_the_attempt_summary(): void
    {
        $attempt = $this->handedInEssayAttempt();
        $essay = $this->essayIds($attempt)[0];

        $data = $this->save($attempt, $essay, 6, $this->versionOf($attempt, $essay))->assertOk()->json('data');

        $this->assertSame(['attempt_id', 'question_id', 'saved', 'points_earned', 'feedback', 'graded_at', 'grade_version', 'attempt'], array_keys($data));
        $this->assertSame(['id', 'status', 'score', 'percentage', 'grades_published', 'ungraded_essays'], array_keys($data['attempt']));
        $this->assertSame(6, $data['points_earned']);
        $this->assertSame('Good.', $data['feedback']);
        $this->assertTrue($data['saved']);
        $this->assertSame('submitted', $data['attempt']['status']);
        $this->assertSame(6, $data['attempt']['score']);
        $this->assertSame(50, $data['attempt']['percentage'], '6 of 12 points.');
        $this->assertSame(0, $data['attempt']['ungraded_essays']);
        $this->assertSame(
            "Line one\n  indented line two",
            ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->value('answer_text'),
            'The student text is untouched.'
        );
    }

    public function test_the_full_response_stays_the_default_for_existing_screens(): void
    {
        $attempt = $this->handedInEssayAttempt();
        $essay = $this->essayIds($attempt)[0];

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt['id']}/grade-essay", ['question_id' => $essay, 'awarded_points' => 3])
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'status', 'score', 'questions', 'answers']]);
    }

    public function test_a_stale_version_is_a_conflict_that_writes_nothing(): void
    {
        $attempt = $this->handedInEssayAttempt();
        $essay = $this->essayIds($attempt)[0];
        $assistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $this->teacher->id]);

        // Both graders open the same ungraded answer.
        $loaded = $this->versionOf($attempt, $essay);
        $this->save($attempt, $essay, 8, $loaded, 'From the assistant.', $assistant)->assertOk();
        $before = ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->firstOrFail()->getAttributes();
        $audits = AuditLog::count();

        $response = $this->save($attempt, $essay, 2, $loaded, 'From the teacher.')
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'grade_conflict')
            ->assertJsonPath('data.current.points_earned', 8)
            ->assertJsonPath('data.current.feedback', 'From the assistant.');

        $this->assertSame($before, ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->firstOrFail()->getAttributes());
        $this->assertSame(8, (int) ExamAttempt::findOrFail($attempt['id'])->score);
        $this->assertSame($audits, AuditLog::count());

        // With the current version the teacher can now save deliberately.
        $this->save($attempt, $essay, 2, $response->json('data.current.grade_version'), 'From the teacher.')->assertOk();
        $this->assertSame(2, (int) ExamAttempt::findOrFail($attempt['id'])->score);
    }

    public function test_a_blank_essay_with_no_answer_row_is_protected_too(): void
    {
        $attempt = $this->handedInEssayAttempt(2);
        $blank = $this->essayIds($attempt)[1];
        $this->assertFalse(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $blank)->exists());

        $loaded = $this->versionOf($attempt, $blank);
        $this->save($attempt, $blank, 0, $loaded)->assertOk()->assertJsonPath('data.points_earned', 0);
        // A second grader who loaded the same blank answer is told, not overwritten.
        $this->save($attempt, $blank, 5, $loaded)->assertStatus(409)->assertJsonPath('data.current.points_earned', 0);
        $this->assertSame(0, ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $blank)->value('points_earned'));
    }

    public function test_a_value_identical_save_writes_and_audits_nothing(): void
    {
        $attempt = $this->handedInEssayAttempt();
        $essay = $this->essayIds($attempt)[0];
        $first = $this->save($attempt, $essay, 5, $this->versionOf($attempt, $essay))->assertOk()->json('data');
        $audits = AuditLog::where('action', 'grade.essay')->count();
        $gradedAt = ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->value('graded_at');

        $again = $this->save($attempt, $essay, 5, $first['grade_version'])->assertOk()->json('data');

        $this->assertFalse($again['saved']);
        $this->assertSame($first['grade_version'], $again['grade_version']);
        $this->assertSame($audits, AuditLog::where('action', 'grade.essay')->count());
        $this->assertEquals($gradedAt, ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->value('graded_at'));

        // Changing only the feedback is a change.
        $changed = $this->save($attempt, $essay, 5, $again['grade_version'], 'Better feedback.')->assertOk()->json('data');
        $this->assertTrue($changed['saved']);
        $this->assertNotSame($again['grade_version'], $changed['grade_version']);
        $this->assertSame($audits + 1, AuditLog::where('action', 'grade.essay')->count());
    }

    public function test_p0_rules_still_hold_on_the_compact_path(): void
    {
        $attempt = $this->start($this->exam());
        $essay = $this->essayIds($attempt)[0];

        $this->save($attempt, $essay, 5, null)->assertStatus(422);
        $this->assertFalse(ExamAnswer::where('attempt_id', $attempt['id'])->where('question_id', $essay)->exists());
    }

    public function test_changing_a_published_score_through_the_compact_path_notifies_once(): void
    {
        $attempt = $this->handedInEssayAttempt();
        $essay = $this->essayIds($attempt)[0];
        $v = $this->save($attempt, $essay, 4, $this->versionOf($attempt, $essay))->json('data.grade_version');
        $this->publish($attempt)->assertOk();
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);

        $v = $this->save($attempt, $essay, 4, $v)->assertOk()->json('data.grade_version');
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 1);

        $this->save($attempt, $essay, 7, $v)->assertOk()->assertJsonPath('data.attempt.grades_published', true);
        Notification::assertSentToTimes($this->student, ResultAvailableNotification::class, 2);
    }

    public function test_a_save_runs_a_bounded_number_of_queries(): void
    {
        $attempt = $this->handedInEssayAttempt();
        $essay = $this->essayIds($attempt)[0];
        $version = $this->versionOf($attempt, $essay);
        $this->app['auth']->forgetGuards();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->save($attempt, $essay, 5, $version)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(16, $count, "grade save ran {$count} queries");
        fwrite(STDERR, "\n[budget] compact grade save: {$count} queries\n");
    }
}
