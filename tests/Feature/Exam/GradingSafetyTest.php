<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
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
    use InteractsWithExams;

    private User $teacher;

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
        $this->student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$this->course->id}/enroll")
            ->assertStatus(201);
        Notification::fake();
    }

    /** A published exam with one single-choice question and `$essays` essay questions (10 points each). */
    private function exam(int $essays = 1, array $attributes = []): Exam
    {
        $exam = $this->makeExam($this->teacher, $this->course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'max_attempts' => 5,
            'show_result_immediately' => false,
        ], $attributes));

        $this->addSingleChoiceQuestion($exam, ['points' => 2]);
        for ($i = 0; $i < $essays; $i++) {
            Question::factory()->create([
                'exam_id' => $exam->id,
                'type' => 'essay',
                'points' => 10,
                'position' => $i + 2,
            ]);
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

    private function essayIds(array $attempt): array
    {
        return array_values(array_map(
            fn ($q) => $q['id'],
            array_filter($attempt['questions'], fn ($q) => $q['question_type'] === 'essay')
        ));
    }

    private function choiceId(array $attempt): int
    {
        return (int) collect($attempt['questions'])->firstWhere('question_type', 'single_choice')['id'];
    }

    private function writeEssay(array $attempt, int $questionId, string $text): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $questionId, 'answer_text' => $text])
            ->assertOk();
    }

    private function submit(array $attempt): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")
            ->assertOk();
    }

    private function grade(array $attempt, int $questionId, mixed $points, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt['id']}/grade-essay", [
                'question_id' => $questionId,
                'awarded_points' => $points,
                'feedback' => 'Reviewed.',
            ]);
    }

    private function publish(array $attempt, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt['id']}/publish-grades");
    }

    /** Everything a rejected publication must leave untouched. */
    private function snapshotOf(int $attemptId): array
    {
        $row = ExamAttempt::findOrFail($attemptId);

        return [
            'attempt' => collect($row->getAttributes())->except(['updated_at'])->all(),
            'answers' => ExamAnswer::where('attempt_id', $attemptId)->orderBy('id')->get()
                ->map(fn ($a) => collect($a->getAttributes())->except(['updated_at'])->all())->all(),
        ];
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
}
