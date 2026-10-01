<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Models\AuditLog;
use App\Models\ExamAttempt;
use App\Models\User;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * PHASE 1 §11-13 — Teacher override for integrity-terminated attempts.
 *
 * The SAME attempt resumes (never attempt #2), the integrity history stays
 * immutable, the decision is audited, and the server timer is restored —
 * never invented.
 */
class ResumeFlaggedAttemptTest extends ApiTestCase
{
    use InteractsWithExams;

    /** @return array{0: User, 1: User, 2: \App\Models\Exam, 3: int} teacher, student, exam, attemptId */
    private function flaggedAttempt(array $examAttrs = []): array
    {
        $teacher = $this->createUserWithRole(\App\Enums\UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
            'show_result_immediately' => false, // resume is only legal before grade publication
        ], $examAttrs));
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(\App\Enums\UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
        $attemptId = $startRes->json('data.id');

        // Save a real answer first — it must survive termination AND resume.
        $questionId = $exam->questions()->first()->id;
        $optionId = $exam->questions()->first()->options()->first()->id;
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/answers", [
                'question_id' => $questionId,
                'option_ids' => [$optionId],
            ])->assertStatus(200);

        // Confirmed repeated violations: exceed the frozen threshold, terminate.
        $attempt = ExamAttempt::findOrFail($attemptId);
        $attempt->forceFill(['violation_warnings' => 6])->save();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/terminate", ['reason' => 'TAB_SWITCH'])
            ->assertStatus(200);

        $attempt->refresh();
        $this->assertSame('integrity_threshold', $attempt->end_reason);

        return [$teacher, $student, $exam, $attemptId];
    }

    private function review(User $actor, int $attemptId, string $decision, ?string $note = null)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attemptId}/integrity/review", array_filter([
                'decision' => $decision,
                'note' => $note,
            ]));
    }

    public function test_resume_continues_the_same_attempt_and_preserves_history(): void
    {
        [$teacher, $student, $exam, $attemptId] = $this->flaggedAttempt();
        $before = ExamAttempt::findOrFail($attemptId);

        $this->review($teacher, $attemptId, 'RESUME', 'Reviewed the events — connectivity issues, not cheating.')
            ->assertStatus(201);

        $after = ExamAttempt::findOrFail($attemptId);
        $this->assertSame($attemptId, $after->id, 'Same attempt — never a new one');
        $this->assertSame(ExamAttemptStatus::InProgress, $after->status);
        $this->assertNull($after->end_reason);
        $this->assertSame('integrity_threshold', $after->previous_end_reason);
        $this->assertNotNull($after->resumed_at);
        $this->assertSame($teacher->id, $after->resumed_by);
        $this->assertSame('reviewed', $after->integrity_status->value);

        // History immutability (§13): warnings + events + answers untouched.
        $this->assertSame($before->violation_warnings, $after->violation_warnings);
        $this->assertSame(1, $after->integrityEvents()->count());
        $this->assertSame('THRESHOLD_TERMINATION', $after->integrityEvents()->first()->event_type->value);
        $this->assertSame(1, $after->answers()->count(), 'Saved answers survive');

        // The student is back in the SAME attempt with the same snapshot.
        $show = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attemptId}")->assertStatus(200);
        $this->assertSame('in_progress', $show->json('data.status'));
    }

    public function test_resume_keeps_a_future_deadline_untouched(): void
    {
        [$teacher, , , $attemptId] = $this->flaggedAttempt();

        $attempt = ExamAttempt::findOrFail($attemptId);
        $originalExpiry = $attempt->expires_at->copy();

        // The deadline is still in the future: it must NOT move at all.
        $this->review($teacher, $attemptId, 'RESUME', 'ok')->assertStatus(201);
        $after = ExamAttempt::findOrFail($attemptId);
        $this->assertTrue($after->expires_at->equalTo($originalExpiry), 'Future deadline untouched');
        $this->assertSame(0, $after->time_restored_seconds);
    }

    public function test_resume_past_deadline_gives_back_exactly_the_time_left_at_termination(): void
    {
        [$teacher, , , $attemptId] = $this->flaggedAttempt();

        $attempt = ExamAttempt::findOrFail($attemptId);
        // Simulate: termination happened 10 minutes BEFORE the deadline,
        // but review happens AFTER the deadline passed.
        $expires = $attempt->expires_at->copy();
        $termination = $attempt->integrityEvents()->first();
        $termination->forceFill(['occurred_at' => $expires->copy()->subMinutes(10)])->save();

        $this->travelTo($expires->copy()->addMinutes(30));

        $this->review($teacher, $attemptId, 'RESUME', 'late review')->assertStatus(201);
        $after = ExamAttempt::findOrFail($attemptId);
        $this->assertSame(600, $after->time_restored_seconds);
        $this->assertSame('integrity_threshold', $after->previous_end_reason);
        $this->assertEqualsWithDelta(now()->addSeconds(600)->getTimestamp(), $after->expires_at->getTimestamp(), 5);
    }

    public function test_resume_is_audited_with_actor_note_and_is_idempotent(): void
    {
        [$teacher, , , $attemptId] = $this->flaggedAttempt();

        $this->review($teacher, $attemptId, 'RESUME', 'mercy once')->assertStatus(201);
        $log = AuditLog::query()->where('action', 'attempt.resume_flagged')->latest('id')->firstOrFail();
        $this->assertSame($teacher->id, $log->actor_id);
        $this->assertSame('mercy once', $log->metadata['note'] ?? null);

        // Second RESUME while active: idempotent — no state change, no duplicate audit.
        $this->review($teacher, $attemptId, 'RESUME', 'again')->assertStatus(201);
        $this->assertSame(1, AuditLog::query()->where('action', 'attempt.resume_flagged')->count());
    }

    public function test_only_integrity_terminated_attempts_can_resume(): void
    {
        $teacher = $this->createUserWithRole(\App\Enums\UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);
        $student = $this->createUserWithRole(\App\Enums\UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $startRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
        $attemptId = $startRes->json('data.id');

        // Voluntary submit — must NOT be resumable.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/submit")->assertStatus(200);

        $this->review($teacher, $attemptId, 'RESUME', 'nope')->assertStatus(422);
        $this->assertSame('submitted_by_student', ExamAttempt::findOrFail($attemptId)->end_reason);
    }

    public function test_disqualify_decision_records_review_and_keeps_events(): void
    {
        [$teacher, , , $attemptId] = $this->flaggedAttempt();

        $this->review($teacher, $attemptId, 'FLAGGED', 'confirmed misconduct')->assertStatus(201);

        $attempt = ExamAttempt::findOrFail($attemptId);
        $this->assertSame('flagged', $attempt->integrity_status->value);
        $this->assertSame('integrity_threshold', $attempt->end_reason);
        $this->assertSame(1, $attempt->integrityEvents()->count(), 'Events are never deleted');
        $this->assertSame(1, AuditLog::query()->where('action', 'attempt.disqualify')->count());
    }

    public function test_other_teachers_cannot_resume_or_review(): void
    {
        [$teacher, , , $attemptId] = $this->flaggedAttempt();
        $teacherB = $this->createUserWithRole(\App\Enums\UserRole::Teacher);

        $this->review($teacherB, $attemptId, 'RESUME', 'intrusion')->assertStatus(403);
        $this->assertNull(ExamAttempt::findOrFail($attemptId)->resumed_at);

        // Sanity: the owning teacher still can.
        $this->review($teacher, $attemptId, 'RESUME', 'ok')->assertStatus(201);
    }
}
