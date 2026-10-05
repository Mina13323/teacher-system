<?php

namespace Tests\Feature\Teacher;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1 — Attempt management UX: grouped by student, server-side student search.
 *
 * Grouping must show, per student: all attempts expandable, best/latest,
 * integrity summary and grading status — while teacher-A/B authorization is
 * never weakened (teacher B cannot read teacher A's exam data through search
 * or grouping).
 */
class AttemptSearchGroupingTest extends ApiTestCase
{
    use InteractsWithExams;

    private function examWithAttempts(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'max_attempts' => 5,
            'show_result_immediately' => true,
        ]);
        $this->addSingleChoiceQuestion($exam, ['points' => 1, 'question_text' => 'Q1']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1, 'question_text' => 'Q2']);

        $students = [];
        foreach (['Amr Zaki', 'Laila Hassan'] as $name) {
            $student = $this->createUserWithRole(UserRole::Student, ['name' => $name]);
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
            $students[] = $student;
        }

        return [$teacher, $course, $exam, $students];
    }

    private function takeAttempt($student, $exam, int $correctCount): ExamAttempt
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);

        $attempt = ExamAttempt::query()
            ->where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->latest('id')
            ->firstOrFail();

        $questions = $attempt->attemptQuestions()->orderBy('id')->get();
        foreach ($questions->take($correctCount) as $aq) {
            $opt = $aq->attemptOptions()->where('is_correct', true)->first();
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                    'question_id' => $aq->question_id,
                    'option_id' => $opt->option_id,
                ])->assertStatus(200);
        }

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")->assertStatus(200);

        return $attempt->fresh();
    }

    public function test_attempts_are_grouped_by_student_with_best_latest_and_grading_state(): void
    {
        [$teacher, , $exam, [$s1, $s2]] = $this->examWithAttempts();

        $this->takeAttempt($s1, $exam, 1); // 50%
        $best = $this->takeAttempt($s1, $exam, 2); // 100%
        $this->takeAttempt($s2, $exam, 0); // 0%

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped")
            ->assertStatus(200);

        $students = collect($res->json('data.students'));
        $this->assertSame(2, $students->count());
        $this->assertSame(2, $res->json('data.summary.students_count'));
        $this->assertSame(3, $res->json('data.summary.attempts_count'));

        $s1Group = $students->firstWhere('student_id', $s1->id);
        $this->assertSame(2, $s1Group['attempts_count']);
        $this->assertCount(2, $s1Group['attempts'], 'All attempts must be expandable per student');
        $this->assertSame(100, (int) $s1Group['best']['percentage']);
        $this->assertSame($best->id, $s1Group['latest']['id']);
        $this->assertSame('passed', $s1Group['latest']['outcome']);
        $this->assertSame(0, $s1Group['pending_grading_count']);

        $s2Group = $students->firstWhere('student_id', $s2->id);
        $this->assertSame(1, $s2Group['attempts_count']);
        $this->assertSame('failed', $s2Group['latest']['outcome']);
    }

    public function test_grouping_summarizes_integrity_and_pending_grading(): void
    {
        [$teacher, , $exam, [$s1, ]] = $this->examWithAttempts();

        $this->takeAttempt($s1, $exam, 1);
        $attempt = ExamAttempt::query()->where('student_id', $s1->id)->latest('id')->firstOrFail();
        $attempt->forceFill([
            'integrity_status' => 'flagged',
            'violation_warnings' => 3,
            'status' => ExamAttemptStatus::Grading->value,
            'grades_published_at' => null,
        ])->save();

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped")
            ->assertStatus(200);

        $group = collect($res->json('data.students'))->firstWhere('student_id', $s1->id);
        $this->assertSame(1, $group['integrity']['flagged_count']);
        $this->assertSame(3, $group['integrity']['violation_warnings_total']);
        $this->assertSame(1, $group['pending_grading_count']);
        $this->assertNull($group['best'], 'A partial grade must not be presented as the student’s best final result.');
        $this->assertSame(1, $res->json('data.summary.flagged_count'));
    }

    public function test_grouping_search_filters_students_server_side(): void
    {
        [$teacher, , $exam, [$s1, $s2]] = $this->examWithAttempts();
        $this->takeAttempt($s1, $exam, 1);
        $this->takeAttempt($s2, $exam, 1);

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped?search=" . urlencode('Laila'))
            ->assertStatus(200);

        $students = collect($res->json('data.students'));
        $this->assertCount(1, $students);
        $this->assertSame($s2->id, $students->first()['student_id']);
    }

    public function test_flat_and_grouped_attempt_views_filter_exact_zero_and_paginate(): void
    {
        [$teacher, , $exam, [$studentA, $studentB]] = $this->examWithAttempts();
        $this->takeAttempt($studentA, $exam, 1); // score 1
        $this->takeAttempt($studentA, $exam, 2); // score 2
        $this->takeAttempt($studentB, $exam, 0); // exact score zero

        $flatZero = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts?score=0&per_page=1")
            ->assertStatus(200);
        $this->assertCount(1, $flatZero->json('data'));
        $this->assertSame(1, $flatZero->json('meta.total'));
        $this->assertSame(0, $flatZero->json('data.0.score'));
        $this->assertSame($studentB->id, $flatZero->json('data.0.student.id'));

        $flatPage = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts?per_page=1&page=1")
            ->assertStatus(200);
        $this->assertCount(1, $flatPage->json('data'));
        $this->assertSame(3, $flatPage->json('meta.total'));
        $this->assertSame(3, $flatPage->json('meta.last_page'));

        $groupedZero = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped?score=0&per_page=1")
            ->assertStatus(200);
        $this->assertCount(1, $groupedZero->json('data.students'));
        $this->assertSame($studentB->id, $groupedZero->json('data.students.0.student_id'));
        $this->assertSame(1, $groupedZero->json('data.summary.attempts_count'));

        $groupedPage = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped?per_page=1&page=1")
            ->assertStatus(200);
        $this->assertSame(2, $groupedPage->json('data.pagination.total'));
        $this->assertSame(2, $groupedPage->json('data.pagination.last_page'));
    }

    public function test_teacher_b_cannot_group_or_search_another_teachers_exam(): void
    {
        [$teacherA, , $exam, ] = $this->examWithAttempts();
        $teacherB = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts/grouped")
            ->assertStatus(403);

        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")
            ->assertStatus(403);
    }

    public function test_student_cannot_use_teacher_search(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/teacher/students?search=anyone')
            ->assertStatus(403);
    }

    public function test_student_search_finds_by_name_code_email_and_phone_scoped_to_owner(): void
    {
        $teacherA = $this->createUserWithRole(UserRole::Teacher);
        $courseA = $this->createCourse($teacherA, ['status' => 'published']);
        $owned = $this->createUserWithRole(UserRole::Student, [
            'name' => 'Nadia Samir',
            'email' => 'nadia.unique@example.com',
            'phone' => '01001234567',
        ]);
        $this->actingAs($owned, 'sanctum')
            ->postJson("/api/v1/student/courses/{$courseA->id}/enroll")->assertStatus(201);

        // A student owned by ANOTHER teacher must never appear in A's search.
        $teacherB = $this->createUserWithRole(UserRole::Teacher);
        $foreign = $this->createUserWithRole(UserRole::Student, [
            'name' => 'Foreign Student',
            'email' => 'foreign.unique@example.com',
        ]);

        $terms = ['Nadia', 'nadia.unique', '0100123'];
        if (! empty($owned->student_code)) {
            $terms[] = $owned->student_code;
        }

        foreach ($terms as $term) {
            $res = $this->actingAs($teacherA, 'sanctum')
                ->getJson('/api/v1/teacher/students?search=' . urlencode($term))
                ->assertStatus(200);

            $ids = collect($res->json('data'))->pluck('id');
            $this->assertContains($owned->id, $ids->all(), "search '{$term}' must find the owned student");
            $this->assertNotContains($foreign->id, $ids->all(), "search '{$term}' must never leak another teacher's student");
        }
    }

    public function test_teacher_attempts_endpoints_auto_finalize_expired_in_progress_attempts(): void
    {
        [$teacher, , $exam, $students] = $this->examWithAttempts();
        $student = $students[0];

        $start = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201);
        $attemptId = $start->json('data.id');

        $attempt = ExamAttempt::findOrFail($attemptId);
        $firstAq = $attempt->attemptQuestions()->firstOrFail();
        $correctOpt = $firstAq->attemptOptions()->where('is_correct', true)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/answers", [
                'question_id' => $firstAq->question_id,
                'option_id' => $correctOpt->option_id,
            ])->assertStatus(200);

        // Simulate a student whose timer expired while their browser was closed (still in_progress).
        ExamAttempt::whereKey($attemptId)->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        // Opening the teacher attempts tab renders the virtual status without mutating or grading on read.
        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")
            ->assertStatus(200);

        $row = collect($res->json('data'))->firstWhere('id', $attemptId);
        $this->assertNotNull($row);
        $this->assertSame(ExamAttemptStatus::Submitted->value, $row['status'], 'Virtual status reflects auto-submit policy');
        $this->assertSame(ExamAttemptStatus::InProgress->value, ExamAttempt::find($attemptId)->status->value, 'GET endpoint must remain read-only and not mutate DB');

        // Background scheduler processes and grades the expired attempt.
        $this->artisan('attempts:process-expired')->assertExitCode(0);

        $attempt->refresh();
        $this->assertSame(ExamAttemptStatus::Submitted->value, $attempt->status->value);
        $this->assertSame(1, (int) $attempt->score);
        $this->assertSame(50, (int) $attempt->percentage);

        // Subsequent GET reflects the persisted score.
        $resAfter = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")
            ->assertStatus(200);

        $rowAfter = collect($resAfter->json('data'))->firstWhere('id', $attemptId);
        $this->assertSame(1, $rowAfter['score']);
        $this->assertSame(50, $rowAfter['percentage']);
    }
}
