<?php

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1 — Result exports: CSV + printable HTML, server-authz-scoped, audited.
 */
class ResultExportTest extends ApiTestCase
{
    use InteractsWithExams;

    private function examWithOneAttempt(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => true,
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student, ['name' => 'Export Student']);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();

        return [$teacher, $exam, $student, $attempt];
    }

    public function test_csv_export_contains_attempt_rows_with_outcome(): void
    {
        [$teacher, $exam, $student, $attempt] = $this->examWithOneAttempt();

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")
            ->assertStatus(200);

        $csv = $res->streamedContent();
        $this->assertStringContainsString('attempt_id', $csv);
        $this->assertStringContainsString('Export Student', $csv);
        $this->assertStringContainsString((string) $attempt->id, $csv);
        $this->assertStringContainsString('in_progress', $csv);
    }

    public function test_export_is_scoped_and_forbidden_for_other_roles(): void
    {
        [$teacher, $exam] = $this->examWithOneAttempt();
        $teacherB = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/results/export")
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/results/export")
            ->assertStatus(403);

        // The owning teacher still can.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/results/export")
            ->assertStatus(200);
    }

    public function test_export_writes_an_audit_entry(): void
    {
        [$teacher, $exam] = $this->examWithOneAttempt();

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")
            ->assertStatus(200);

        $log = AuditLog::query()->where('action', 'results.export')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($exam->id, $log->target_id);
        $this->assertSame('csv', $log->metadata['format']);
        $this->assertSame('1', $log->metadata['rows']);
    }

    public function test_printable_export_renders_html_sheet(): void
    {
        [$teacher, $exam] = $this->examWithOneAttempt();

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/results/export?format=print")
            ->assertStatus(200);

        $html = $res->getContent();
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('Exam results', $html);
    }
}
