<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Server-authoritative exam timing.
 *
 * Business rule (worked example, duration 30, window 10:00 -> 14:00):
 *   10:00 entry -> 30 min, 10:10 -> 20, 10:15 -> 15, 10:29 -> 1,
 *   10:30 -> cannot start, 10:31 -> cannot start.
 *
 * effective_deadline = min(starts_at + duration_minutes, ends_at), so with
 * ends_at 10:20 the deadline is 10:20 even though duration is 30.
 *
 * Legacy exams (starts_at and ends_at both null) keep the previous
 * duration-per-attempt behaviour (Option A) so existing records are unaffected.
 */
class ExamWindowTest extends ApiTestCase
{
    use InteractsWithExams;

    /** Official window opens at this instant (UTC, the app timezone). */
    private const OPENS = '2026-10-10 10:00:00';

    private function at(string $offsetMinutes = '0'): Carbon
    {
        return Carbon::parse(self::OPENS, 'UTC')->addMinutes((float) $offsetMinutes);
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Exam}
     */
    private function enrolledStudent(array $examAttributes): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, array_merge([
            'duration_minutes' => 30,
        ], $examAttributes));

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $exam];
    }

    // ---------------------------------------------------------------------
    // Legacy exams (Option A): null window keeps duration-per-attempt.
    // ---------------------------------------------------------------------

    public function test_legacy_exam_gives_full_duration_on_late_entry(): void
    {
        [$student, $exam] = $this->enrolledStudent(['starts_at' => null, 'ends_at' => null]);

        $this->travelTo($this->at('45'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();

        $this->assertTrue(
            $attempt->expires_at->equalTo($attempt->started_at->copy()->addMinutes(30)),
            'Legacy exams must keep expires_at = started_at + duration_minutes.'
        );
    }

    // ---------------------------------------------------------------------
    // Windowed exam, ends_at far in the future => deadline = starts_at + 30.
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int}>  [entry offset, expected minutes left]
     */
    public static function lateEntryProvider(): array
    {
        return [
            'entry at open' => ['0', 30],
            'entry at 10:10' => ['10', 20],
            'entry at 10:15' => ['15', 15],
            'entry at 10:29' => ['29', 1],
        ];
    }

    #[DataProvider('lateEntryProvider')]
    public function test_windowed_entry_receives_only_the_time_left(string $offset, int $minutesLeft): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $now = $this->at($offset);
        $this->travelTo($now);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();

        $this->assertTrue(
            $attempt->expires_at->equalTo($this->at('30')),
            'A windowed attempt must expire at the global deadline, not at entry + duration.'
        );
        $this->assertSame(
            $minutesLeft,
            (int) $attempt->started_at->diffInMinutes($attempt->expires_at),
            "Entry at +{$offset} min should leave {$minutesLeft} min."
        );
    }

    public function test_windowed_entry_exactly_at_deadline_is_rejected(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $this->travelTo($this->at('30'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(422);

        $this->assertDatabaseCount('exam_attempts', 0);
    }

    public function test_windowed_entry_after_deadline_is_rejected(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $this->travelTo($this->at('31'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(422);

        $this->assertDatabaseCount('exam_attempts', 0);
    }

    public function test_entry_before_the_window_opens_is_rejected(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $this->travelTo($this->at('-1'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(422);

        $this->assertDatabaseCount('exam_attempts', 0);
    }

    // ---------------------------------------------------------------------
    // Windowed exam whose ends_at is earlier than starts_at + duration.
    // ---------------------------------------------------------------------

    public function test_earlier_ends_at_caps_the_effective_deadline(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 10:20:00',
        ]);

        $this->travelTo($this->at('10'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();

        $this->assertTrue($attempt->expires_at->equalTo($this->at('20')));
        $this->assertSame(10, (int) $attempt->started_at->diffInMinutes($attempt->expires_at));
    }

    public function test_one_minute_before_a_tight_deadline_leaves_one_minute(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 10:20:00',
        ]);

        $this->travelTo($this->at('19'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();

        $this->assertTrue($attempt->expires_at->equalTo($this->at('20')));
        $this->assertSame(1, (int) $attempt->started_at->diffInMinutes($attempt->expires_at));
    }

    public function test_entry_at_a_tight_ends_at_is_rejected(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 10:20:00',
        ]);

        $this->travelTo($this->at('20'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(422);

        $this->assertDatabaseCount('exam_attempts', 0);
    }

    // ---------------------------------------------------------------------
    // Resume and expiry.
    // ---------------------------------------------------------------------

    public function test_resuming_an_attempt_does_not_reset_the_timer(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $this->travelTo($this->at('0'));
        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $this->travelTo($this->at('10'));
        $second = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();
        $this->assertTrue(
            $attempt->expires_at->equalTo($this->at('30')),
            'Resuming must not extend the deadline.'
        );
    }

    public function test_answering_after_the_deadline_expires_the_attempt(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $this->travelTo($this->at('0'));
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();
        $question = Question::where('exam_id', $exam->id)->sole();

        $this->travelTo($this->at('31'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $question->options()->where('is_correct', true)->value('id'),
            ])
            ->assertStatus(422);

        $this->assertSame(
            ExamAttemptStatus::Expired->value,
            $attempt->fresh()->status->value
        );
    }

    // ---------------------------------------------------------------------
    // Window validation.
    // ---------------------------------------------------------------------

    public function test_partial_window_is_rejected_on_create(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Windowed exam',
                'duration_minutes' => 30,
                'starts_at' => self::OPENS,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertDatabaseCount('exams', 0);
    }

    public function test_inverted_window_is_rejected_on_create(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Inverted window',
                'duration_minutes' => 30,
                'starts_at' => self::OPENS,
                'ends_at' => '2026-10-10 09:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);

        $this->assertDatabaseCount('exams', 0);
    }

    public function test_zero_length_window_is_rejected(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Zero window',
                'duration_minutes' => 30,
                'starts_at' => self::OPENS,
                'ends_at' => self::OPENS,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_partial_update_cannot_leave_half_a_window(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['starts_at' => null, 'ends_at' => null]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", ['ends_at' => '2026-10-10 14:00:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertNull($exam->fresh()->ends_at);
    }

    public function test_a_complete_window_can_be_set_and_cleared(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", [
                'starts_at' => self::OPENS,
                'ends_at' => '2026-10-10 14:00:00',
            ])
            ->assertStatus(200);

        $this->assertTrue($exam->fresh()->isWindowed());

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", [
                'starts_at' => null,
                'ends_at' => null,
            ])
            ->assertStatus(200);

        $this->assertFalse($exam->fresh()->isWindowed());
    }

    // ---------------------------------------------------------------------
    // The client is never authoritative.
    // ---------------------------------------------------------------------

    public function test_client_supplied_timing_fields_are_ignored(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $this->travelTo($this->at('10'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", [
                'started_at' => '2020-01-01T00:00:00.000000Z',
                'expires_at' => '2099-01-01T00:00:00.000000Z',
                'remaining_seconds' => 999999,
                'elapsed_seconds' => 0,
                'deadline' => '2099-01-01T00:00:00.000000Z',
            ])
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();

        $this->assertTrue($attempt->started_at->equalTo($this->at('10')));
        $this->assertTrue($attempt->expires_at->equalTo($this->at('30')));
    }

    // ---------------------------------------------------------------------
    // Resource exposure boundary.
    // ---------------------------------------------------------------------

    public function test_teacher_resource_exposes_the_full_window(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
            'duration_minutes' => 30,
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.starts_at', $this->at('0')->toISOString())
            ->assertJsonPath('data.ends_at', Carbon::parse('2026-10-10 14:00:00', 'UTC')->toISOString())
            ->assertJsonPath('data.is_windowed', true)
            ->assertJsonPath('data.effective_deadline', $this->at('30')->toISOString());
    }

    public function test_student_resource_exposes_only_what_the_timer_needs(): void
    {
        [$student, $exam] = $this->enrolledStudent([
            'starts_at' => self::OPENS,
            'ends_at' => '2026-10-10 14:00:00',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.starts_at', $this->at('0')->toISOString())
            ->assertJsonPath('data.effective_deadline', $this->at('30')->toISOString());

        $this->assertArrayNotHasKey('ends_at', $response->json('data'));
    }

    // ---------------------------------------------------------------------
    // Essay grading path is restricted to snapshot essay questions.
    // ---------------------------------------------------------------------

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Exam, 2: \App\Models\ExamAttempt, 3: \App\Models\Question}
     */
    private function submittedMcqAttempt(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => ExamStatus::Published,
            'duration_minutes' => 30,
        ]);

        $question = $this->addSingleChoiceQuestion($exam, ['points' => 5]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('exam_id', $exam->id)->sole();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        return [$teacher, $exam, $attempt->fresh(), $question];
    }

    public function test_non_essay_snapshot_question_cannot_enter_the_essay_grading_path(): void
    {
        [$teacher, , $attempt, $question] = $this->submittedMcqAttempt();

        $this->assertSame(
            QuestionType::SingleChoice->value,
            ExamAttemptQuestion::where('attempt_id', $attempt->id)->value('question_type')
        );

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 5,
                'feedback' => 'Awarded by mistake.',
            ])
            ->assertStatus(422);
    }

    public function test_rejected_essay_grading_mutates_nothing(): void
    {
        [$teacher, , $attempt, $question] = $this->submittedMcqAttempt();

        $answerBefore = ExamAnswer::where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->sole();
        $scoreBefore = $attempt->score;
        $percentageBefore = $attempt->percentage;

        // Sanity: the MCQ was auto-graded for full marks on submit, so a
        // successful manual grade of 0 would be clearly visible.
        $this->assertSame(5, (int) $answerBefore->points_earned);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 0,
                'feedback' => 'Should not be written.',
            ])
            ->assertStatus(422);

        $answerAfter = ExamAnswer::where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->sole();
        $attemptAfter = $attempt->fresh();

        // The auto-graded result survived untouched...
        $this->assertSame($answerBefore->points_earned, $answerAfter->points_earned);
        $this->assertSame($answerBefore->is_correct, $answerAfter->is_correct);
        $this->assertSame($scoreBefore, $attemptAfter->score);
        $this->assertSame($percentageBefore, $attemptAfter->percentage);

        // ...and no manual-grading metadata was written.
        $this->assertNull($answerAfter->feedback);
        $this->assertNull($answerAfter->graded_by);
        $this->assertNull($answerAfter->graded_at);

        // The frozen snapshot is unchanged too.
        $this->assertSame(
            QuestionType::SingleChoice->value,
            ExamAttemptQuestion::where('attempt_id', $attempt->id)->value('question_type')
        );
    }

    public function test_unknown_snapshot_question_type_is_rejected(): void
    {
        [$teacher, , $attempt, $question] = $this->submittedMcqAttempt();

        // Simulate a legacy snapshot row written before question_type existed.
        ExamAttemptQuestion::where('attempt_id', $attempt->id)
            ->update(['question_type' => null]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 1,
            ])
            ->assertStatus(422);
    }

    public function test_award_above_the_snapshot_maximum_is_rejected_with_422(): void
    {
        [$teacher, , $attempt, $question] = $this->submittedMcqAttempt();

        // Make the snapshot an essay so the only remaining failure is the bound.
        ExamAttemptQuestion::where('attempt_id', $attempt->id)
            ->update(['question_type' => QuestionType::Essay->value]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 999,
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // Dormant academic columns must not be silently accepted as if they work.
    // ---------------------------------------------------------------------

    public function test_dormant_academic_columns_are_not_persisted_or_echoed(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Exam with dormant fields',
                'duration_minutes' => 30,
                'academic_year' => 'secondary_3',
                'academic_subject' => 'history',
            ])
            ->assertStatus(201);

        $exam = Exam::where('title', 'Exam with dormant fields')->sole();

        // Not in $fillable, so nothing is written.
        $this->assertNull($exam->academic_year);
        $this->assertNull($exam->academic_subject);

        // And the API never pretends it stored them.
        $this->assertArrayNotHasKey('academic_year', $response->json('data'));
        $this->assertArrayNotHasKey('academic_subject', $response->json('data'));
    }
}
