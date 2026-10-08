<?php

namespace Tests\Feature\Performance;

use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Student and teacher pages that run during exams: no per-row queries, no
 * class-wide counts on student pages, and one request for the teacher's
 * integrity review list, with the same authorization as before.
 */
class LmsQueryBudgetTest extends ApiTestCase
{
    use InteractsWithExams;

    private User $teacher;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
    }

    private function enrolledStudent(): User
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$this->course->id}/enroll")->assertStatus(201);
        $this->app['auth']->forgetGuards();

        return $student;
    }

    private function startAs(User $student, Exam $exam): int
    {
        $id = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->json('data.id');
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function countQueries(callable $fn): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->app['auth']->forgetGuards();
        $fn();

        return $count;
    }

    public function test_the_student_exam_list_counts_only_the_students_own_attempts(): void
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 30]);
        $me = $this->enrolledStudent();
        $others = [$this->enrolledStudent(), $this->enrolledStudent()];
        foreach ($others as $other) {
            $this->startAs($other, $exam);
        }
        $this->startAs($me, $exam);

        $row = collect($this->actingAs($me, 'sanctum')->getJson('/api/v1/student/exams')->assertOk()->json('data'))
            ->firstWhere('id', $exam->id);

        $this->assertSame(1, $row['attempts_count']);
        $this->assertSame(1, $row['questions_count']);
    }

    public function test_the_teacher_attempt_list_does_not_query_per_student(): void
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 30]);
        $budgets = [];
        foreach ([2, 12] as $total) {
            while (ExamAttempt::where('exam_id', $exam->id)->count() < $total) {
                $this->startAs($this->enrolledStudent(), $exam);
            }

            // A fresh model each time, so the teacher's roles are not already loaded.
            $budgets[$total] = $this->countQueries(fn () => $this->actingAs($this->teacher->fresh(), 'sanctum')
                ->getJson("/api/v1/teacher/exams/{$exam->id}/attempts?per_page=15")
                ->assertOk()
                ->assertJsonCount($total, 'data'));
        }

        $this->assertSame($budgets[2], $budgets[12]);

        // Each row still carries the student's access fields and roles.
        $first = $this->actingAs($this->teacher, 'sanctum')->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")->json('data.0.student');
        $this->assertTrue($first['has_active_access']);
        $this->assertSame('active', $first['access_status']);
        $this->assertSame(['student'], $first['roles']);
    }

    public function test_the_integrity_review_list_returns_flagged_attempts_in_one_request(): void
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 30]);
        $flagged = $this->startAs($this->enrolledStudent(), $exam);
        $cleared = $this->startAs($this->enrolledStudent(), $exam);
        $normal = $this->startAs($this->enrolledStudent(), $exam);
        ExamAttempt::whereKey($flagged)->update(['integrity_status' => IntegrityStatus::Flagged->value]);
        ExamAttempt::whereKey($cleared)->update(['integrity_status' => IntegrityStatus::Cleared->value]);

        $rows = $this->actingAs($this->teacher, 'sanctum')->getJson('/api/v1/teacher/integrity/attempts')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing([$flagged, $cleared], array_column($rows, 'id'));
        $this->assertNotContains($normal, array_column($rows, 'id'));
        $row = collect($rows)->firstWhere('id', $flagged);
        $this->assertSame($exam->title, $row['exam_title']);
        $this->assertSame($this->course->title, $row['course_title']);
        $this->assertSame('flagged', $row['integrity_status']);
        $this->assertNotEmpty($row['student']['name']);
    }

    public function test_the_integrity_review_list_never_shows_another_teachers_attempts(): void
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 30]);
        $flagged = $this->startAs($this->enrolledStudent(), $exam);
        ExamAttempt::whereKey($flagged)->update(['integrity_status' => IntegrityStatus::Flagged->value]);

        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($otherTeacher, 'sanctum')->getJson('/api/v1/teacher/integrity/attempts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // The per-exam endpoint refuses that teacher too: the list matches it.
        $this->app['auth']->forgetGuards();
        $this->actingAs($otherTeacher, 'sanctum')->getJson("/api/v1/teacher/exams/{$exam->id}/attempts")->assertStatus(403);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->app['auth']->forgetGuards();
        $this->actingAs($student, 'sanctum')->getJson('/api/v1/teacher/integrity/attempts')->assertStatus(403);

        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->app['auth']->forgetGuards();
        $this->assertSame([$flagged], array_column(
            $this->actingAs($admin, 'sanctum')->getJson('/api/v1/teacher/integrity/attempts')->assertOk()->json('data'),
            'id'
        ));
    }

    public function test_the_sweep_finalizes_attempts_whose_exam_window_closed(): void
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 60]);
        $closedWindow = $this->startAs($this->enrolledStudent(), $exam);
        $exam->forceFill(['ends_at' => now()->subMinute()])->saveQuietly();

        $openExam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 60]);
        $stillOpen = $this->startAs($this->enrolledStudent(), $openExam);

        $deletedExam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 60]);
        $onDeletedExam = $this->startAs($this->enrolledStudent(), $deletedExam);
        $deletedExam->forceFill(['ends_at' => now()->subMinute()])->saveQuietly();
        $deletedExam->delete();

        $this->artisan('attempts:process-expired')->assertSuccessful();

        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($closedWindow)->status);
        $this->assertSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($stillOpen)->status);
        // As before: the attempt's exam relation includes deleted exams, so
        // their window still closes attempts.
        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($onDeletedExam)->status);
    }

    public function test_the_sweep_processes_the_oldest_attempts_first_within_its_limit(): void
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['max_attempts' => 5, 'duration_minutes' => 30]);
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->startAs($this->enrolledStudent(), $exam);
        }
        $this->travel(31)->minutes();

        $this->artisan('attempts:process-expired', ['--limit' => 2])->assertSuccessful();

        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($ids[0])->status);
        $this->assertNotSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($ids[1])->status);
        $this->assertSame(ExamAttemptStatus::InProgress, ExamAttempt::findOrFail($ids[2])->status);
    }
}
