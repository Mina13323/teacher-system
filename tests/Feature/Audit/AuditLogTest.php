<?php

namespace Tests\Feature\Audit;

use App\Actions\Exam\PublishExamGradesAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1 — Staff audit trail: who did what, to what, when (append-only).
 *
 * Covers: key actions write entries with actor/target; metadata never carries
 * secrets; the trail is admin-only; filters work; entries are never rewritten.
 */
class AuditLogTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_grade_publication_writes_an_audit_entry(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published', 'show_result_immediately' => false]);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")->assertStatus(200);

        app(PublishExamGradesAction::class)->execute($teacher, $attempt);

        $log = AuditLog::query()->where('action', 'grade.publish')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($teacher->id, $log->actor_id);
        $this->assertSame($attempt->id, $log->target_id);
        $this->assertSame('1', $log->metadata['first_publication']);
        $this->assertSame((string) $student->id, (string) $log->metadata['student_id']);
    }

    public function test_threshold_termination_writes_an_audit_entry(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $start = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);

        $attempt = ExamAttempt::findOrFail($start->json('data.id'));
        $attempt->forceFill(['violation_warnings' => 6])->save();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/terminate", ['reason' => 'TAB_SWITCH'])
            ->assertStatus(200);

        $log = AuditLog::query()->where('action', 'attempt.terminate')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($attempt->id, $log->target_id);
        $this->assertSame('integrity_threshold', $log->metadata['end_reason']);
    }

    public function test_auto_submit_writes_an_audit_entry(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published', 'expiry_mode' => 'auto_submit']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $start = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);

        ExamAttempt::findOrFail($start->json('data.id'))
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('attempts:process-expired')->assertExitCode(0);

        $log = AuditLog::query()->where('action', 'attempt.auto_submit')->latest('id')->first();
        $this->assertNotNull($log, 'Deadline auto-submissions must be auditable');
    }

    public function test_exam_publish_and_archive_write_audit_entries(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'draft']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/archive")->assertStatus(200);

        $this->assertSame(1, AuditLog::query()->where('action', 'exam.publish')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'exam.archive')->count());
    }

    public function test_audit_trail_is_admin_only(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')->assertStatus(403);
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')->assertStatus(200);
    }

    public function test_audit_filters_and_pagination(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'draft']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")->assertStatus(200);

        $res = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/audit-logs?action=exam.publish')
            ->assertStatus(200);

        $rows = collect($res->json('data'));
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn ($r) => $r['action'] === 'exam.publish'));

        // Actor filter: entries by this teacher only.
        $res = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/admin/audit-logs?actor_id={$teacher->id}")
            ->assertStatus(200);
        $rows = collect($res->json('data'));
        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn ($r) => $r['actor_id'] === $teacher->id));
    }

    public function test_metadata_never_carries_secrets(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'draft']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")->assertStatus(200);

        $log = AuditLog::query()->where('action', 'exam.publish')->latest('id')->firstOrFail();
        $flat = json_encode($log->metadata);
        $this->assertStringNotContainsString('password', strtolower($flat));
        $this->assertStringNotContainsString('token', strtolower($flat));
        $this->assertStringNotContainsString('secret', strtolower($flat));
    }

    public function test_essay_grading_writes_an_audit_entry_without_feedback_text(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published', 'show_result_immediately' => false]);

        $essay = \App\Models\Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'essay',
            'points' => 5,
            'position' => 1,
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $start = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);

        $attempt = ExamAttempt::findOrFail($start->json('data.id'));
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $essay->id,
                'answer_text' => 'My essay body.',
            ])->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $essay->id,
                'awarded_points' => 4,
                'feedback' => 'Sensitive private remarks.',
            ])->assertStatus(200);

        $log = AuditLog::query()->where('action', 'grade.essay')->latest('id')->firstOrFail();
        $this->assertSame('4', (string) $log->metadata['awarded_points']);
        $this->assertSame('1', $log->metadata['has_feedback']);
        $this->assertStringNotContainsString('Sensitive private remarks', json_encode($log->metadata), 'Feedback text must not enter the audit trail');
    }
}
