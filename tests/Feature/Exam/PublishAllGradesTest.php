<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\BuildsEssayAttempts;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1-D: POST /teacher/exams/{exam}/publish-grades publishes every fully
 * graded, unpublished, handed-in attempt of the exam and nothing else.
 */
class PublishAllGradesTest extends ApiTestCase
{
    use BuildsEssayAttempts, InteractsWithExams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEssayFixture();
    }

    private function studentAttempt($exam, bool $grade, bool $submit = true): array
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$this->course->id}/enroll")->assertStatus(201);
        $attempt = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)->json('data');
        $attempt['student'] = $student;
        if ($submit) {
            $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();
            if ($grade) {
                $this->grade($attempt, $this->essayIds($attempt)[0], 6)->assertOk();
            }
        }

        return $attempt;
    }

    private function publishAll($exam, array $payload = ['confirmed' => true], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish-grades", $payload);
    }

    public function test_only_fully_graded_unpublished_attempts_are_published(): void
    {
        $exam = $this->exam(1);
        $ready1 = $this->studentAttempt($exam, grade: true);
        $ready2 = $this->studentAttempt($exam, grade: true);
        $awaiting = $this->studentAttempt($exam, grade: false);
        $running = $this->studentAttempt($exam, grade: false, submit: false);
        $already = $this->studentAttempt($exam, grade: true);
        $this->publish($already)->assertOk();
        $runningBefore = ExamAttempt::findOrFail($running['id'])->getAttributes();

        $res = $this->publishAll($exam)->assertOk()->json('data');

        $this->assertSame(['published' => 2, 'blocked_ungraded' => 0, 'skipped' => 0, 'failed' => 0], array_intersect_key($res, array_flip(['published', 'blocked_ungraded', 'skipped', 'failed'])));
        $this->assertFalse($res['has_more']);
        $this->assertSame(['ready_to_publish' => 0, 'awaiting_grading' => 1, 'published' => 3], $res['attempts']);

        foreach ([$ready1, $ready2, $already] as $a) {
            $this->assertSame(ExamAttemptStatus::Published, ExamAttempt::findOrFail($a['id'])->status);
            Notification::assertSentToTimes($a['student'], ResultAvailableNotification::class, 1);
        }
        $this->assertSame(ExamAttemptStatus::Grading, ExamAttempt::findOrFail($awaiting['id'])->status);
        $this->assertNull(ExamAttempt::findOrFail($awaiting['id'])->grades_published_at);
        Notification::assertNotSentTo($awaiting['student'], ResultAvailableNotification::class);
        $this->assertSame($runningBefore, ExamAttempt::findOrFail($running['id'])->getAttributes(), 'A running exam is never touched.');
    }

    public function test_one_refused_attempt_does_not_roll_back_the_others(): void
    {
        $exam = $this->exam(1);
        $good = $this->studentAttempt($exam, grade: true);
        // An anomalous row: says `submitted` but its essay is ungraded.
        $odd = $this->studentAttempt($exam, grade: false);
        ExamAttempt::whereKey($odd['id'])->update(['status' => ExamAttemptStatus::Submitted->value]);

        $res = $this->publishAll($exam)->assertOk()->json('data');

        $this->assertSame(1, $res['published']);
        $this->assertSame(1, $res['blocked_ungraded']);
        $this->assertNotNull(ExamAttempt::findOrFail($good['id'])->grades_published_at);
        $this->assertNull(ExamAttempt::findOrFail($odd['id'])->grades_published_at);
    }

    public function test_batches_are_bounded_and_retrying_never_double_publishes(): void
    {
        $exam = $this->exam(1);
        $attempts = array_map(fn () => $this->studentAttempt($exam, grade: true), range(1, 3));

        $first = $this->publishAll($exam, ['confirmed' => true, 'limit' => 2])->assertOk()->json('data');
        $this->assertSame(2, $first['published']);
        $this->assertTrue($first['has_more']);

        $second = $this->publishAll($exam, ['confirmed' => true, 'limit' => 2])->assertOk()->json('data');
        $this->assertSame(1, $second['published']);
        $this->assertFalse($second['has_more']);

        $third = $this->publishAll($exam)->assertOk()->json('data');
        $this->assertSame(0, $third['published']);

        foreach ($attempts as $a) {
            Notification::assertSentToTimes($a['student'], ResultAvailableNotification::class, 1);
        }
        $this->publishAll($exam, ['confirmed' => true, 'limit' => 51])->assertStatus(422);
    }

    public function test_confirmation_and_authorization_are_required(): void
    {
        $exam = $this->exam(1);
        $a = $this->studentAttempt($exam, grade: true);

        $this->publishAll($exam, [])->assertStatus(422);
        $this->publishAll($exam, ['confirmed' => false])->assertStatus(422);
        $this->publishAll($exam, ['confirmed' => true], $a['student'])->assertStatus(403);

        Config::set('app.co_teaching', false);
        $stranger = $this->createUserWithRole(UserRole::Teacher);
        $this->publishAll($exam, ['confirmed' => true], $stranger)->assertStatus(403);
        $foreignAssistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $stranger->id]);
        $this->publishAll($exam, ['confirmed' => true], $foreignAssistant)->assertStatus(403);
        $this->assertNull(ExamAttempt::findOrFail($a['id'])->grades_published_at);

        $ownAssistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $this->teacher->id]);
        $this->publishAll($exam, ['confirmed' => true], $ownAssistant)->assertOk()->assertJsonPath('data.published', 1);
    }

    public function test_an_exam_without_essays_publishes_its_handed_in_attempts(): void
    {
        $exam = $this->exam(0);
        $a = $this->studentAttempt($exam, grade: false);

        $this->publishAll($exam)->assertOk()->assertJsonPath('data.published', 1);
        $this->assertNotNull(ExamAttempt::findOrFail($a['id'])->grades_published_at);
    }

    public function test_publication_work_per_attempt_is_measured_and_bounded(): void
    {
        $exam = $this->exam(1);
        foreach (range(1, 4) as $i) {
            $this->studentAttempt($exam, grade: true);
        }
        $this->app['auth']->forgetGuards();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->publishAll($exam, ['confirmed' => true], $this->teacher->fresh())->assertOk()->assertJsonPath('data.published', 4);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        $perAttempt = $total / 4;
        $this->assertLessThanOrEqual(20, $perAttempt);
        fwrite(STDERR, "\n[budget] publish-all: {$total} queries for 4 attempts (~".round($perAttempt, 1)." per attempt incl. notification + audit)\n");
    }
}
