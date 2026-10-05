<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class ExamLifecycleConcurrencyHardeningTest extends ApiTestCase
{
    use InteractsWithExams;

    /**
     * Requirement (f): teacher attempts GET with many expired attempts is strictly read-only,
     * performs zero mutations or grading transactions, and does not time out.
     * Requirement (h): expired attempts display correctly before background finalization.
     */
    public function test_teacher_attempts_get_with_many_expired_attempts_is_read_only_and_displays_virtual_status(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'duration_minutes' => 30,
            'expiry_mode' => 'auto_submit',
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 2, 'question_text' => 'Q1']);

        // Create 25 students with expired in-progress attempts
        $attempts = [];
        for ($i = 0; $i < 25; $i++) {
            $student = $this->createUserWithRole(UserRole::Student, ['name' => "Student {$i}"]);
            $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
            $startRes = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
            $att = ExamAttempt::findOrFail($startRes->json('data.id'));
            $att->forceFill([
                'expires_at' => now()->subMinutes(10),
                'status' => ExamAttemptStatus::InProgress->value,
            ])->save();
            $attempts[] = $att;
        }

        // Before teacher GET, all attempts are InProgress in DB
        $this->assertSame(25, ExamAttempt::query()->where('exam_id', $exam->id)->where('status', ExamAttemptStatus::InProgress->value)->count());

        // Teacher requests attempts list
        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts?per_page=50")
            ->assertStatus(200);

        $data = $res->json('data');
        $this->assertCount(25, $data);

        // Every row displays virtual status 'submitted' (auto-submit policy)
        foreach ($data as $row) {
            $this->assertSame(ExamAttemptStatus::Submitted->value, $row['status']);
            $this->assertNull($row['score'], 'Score is not computed synchronously on read');
        }

        // CRITICAL: Database must NOT have been mutated during GET
        $this->assertSame(25, ExamAttempt::query()->where('exam_id', $exam->id)->where('status', ExamAttemptStatus::InProgress->value)->count(),
            'GET must remain purely read-only and never perform bulk writes'
        );
    }

    /**
     * Requirement (g): background finalization still finalizes those attempts correctly.
     */
    public function test_background_finalization_still_finalizes_many_expired_attempts_correctly(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'duration_minutes' => 30,
            'expiry_mode' => 'auto_submit',
            'pass_percentage' => 50,
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 2, 'question_text' => 'Q1']);

        // Create 10 expired attempts with saved answers
        for ($i = 0; $i < 10; $i++) {
            $student = $this->createUserWithRole(UserRole::Student);
            $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
            $startRes = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
            $att = ExamAttempt::findOrFail($startRes->json('data.id'));

            $aq = $att->attemptQuestions()->first();
            $opt = $aq->attemptOptions()->where('is_correct', true)->first();
            $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$att->id}/answers", [
                'question_id' => $aq->question_id,
                'option_id' => $opt->option_id,
            ])->assertStatus(200);

            $att->forceFill([
                'expires_at' => now()->subMinutes(5),
                'status' => ExamAttemptStatus::InProgress->value,
            ])->save();
        }

        // Execute background scheduler command
        $this->artisan('attempts:process-expired', ['--limit' => 50])
            ->assertExitCode(0);

        // All 10 are now persisted as Submitted, with scores computed
        $finalized = ExamAttempt::query()->where('exam_id', $exam->id)->get();
        $this->assertCount(10, $finalized);
        foreach ($finalized as $att) {
            $this->assertSame(ExamAttemptStatus::Submitted->value, $att->status->value);
            $this->assertSame(2, (int) $att->score);
            $this->assertSame('auto_submit_at_deadline', $att->end_reason);
        }
    }

    /**
     * Requirement (d): double submit is strictly idempotent and does not duplicate or corrupt state.
     */
    public function test_double_submit_is_strictly_idempotent(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'duration_minutes' => 30,
            'expiry_mode' => 'auto_submit',
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 2, 'question_text' => 'Q1']);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $startRes = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
        $att = ExamAttempt::findOrFail($startRes->json('data.id'));

        // First submit
        $res1 = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$att->id}/submit");
        $res1->assertStatus(200);

        $att->refresh();
        $firstSubmittedAt = $att->submitted_at;
        $firstScore = $att->score;

        // Second submit immediately after
        $res2 = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$att->id}/submit");
        $res2->assertStatus(200);

        $att->refresh();
        $this->assertTrue($att->submitted_at->equalTo($firstSubmittedAt), 'Submission timestamp must not change on duplicate submit');
        $this->assertSame($firstScore, $att->score, 'Score must not change on duplicate submit');
        $this->assertSame(ExamAttemptStatus::Submitted->value, $att->status->value);
    }

    /**
     * Requirement (e): late answer save near/after deadline finalizes attempt per policy
     * and does not accept the late mutation if expired.
     */
    public function test_late_answer_save_after_deadline_is_rejected_and_finalizes_safely(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'duration_minutes' => 30,
            'expiry_mode' => 'auto_submit',
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 2, 'question_text' => 'Q1']);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $startRes = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
        $att = ExamAttempt::findOrFail($startRes->json('data.id'));

        // Advance past expires_at
        $att->forceFill([
            'expires_at' => now()->subSeconds(10),
            'status' => ExamAttemptStatus::InProgress->value,
        ])->save();

        $aq = $att->attemptQuestions()->first();
        $opt = $aq->attemptOptions()->first();

        // Late answer save request
        $res = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$att->id}/answers", [
            'question_id' => $aq->question_id,
            'option_id' => $opt->option_id,
        ]);

        // Must reject mutation with 422
        $res->assertStatus(422);

        // Attempt should be auto-finalized per policy without corruption
        $att->refresh();
        $this->assertFalse($att->status->isInProgress());
    }

    /**
     * Requirement (h): expired attempts in strict 'expire' mode display 'expired' virtual status.
     */
    public function test_expired_attempt_strict_mode_displays_expired_status_before_background_finalization(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'duration_minutes' => 30,
            'expiry_mode' => 'expire', // strict expire mode
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 2, 'question_text' => 'Q1']);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $startRes = $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
        $att = ExamAttempt::findOrFail($startRes->json('data.id'));

        // Past deadline, still in_progress in DB
        $att->forceFill([
            'expires_at' => now()->subMinutes(5),
            'status' => ExamAttemptStatus::InProgress->value,
        ])->save();

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")
            ->assertStatus(200);

        $row = collect($res->json('data'))->firstWhere('id', $att->id);
        $this->assertNotNull($row);
        $this->assertSame(ExamAttemptStatus::Expired->value, $row['status'], 'Strict mode displays expired virtual status');
    }
}
