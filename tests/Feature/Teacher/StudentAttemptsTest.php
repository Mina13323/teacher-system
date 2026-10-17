<?php

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1-E: GET /teacher/students/{student}/attempts — the student page's
 * "exams and attempts" list, paginated and scoped to the viewer's exams.
 */
class StudentAttemptsTest extends ApiTestCase
{
    use InteractsWithExams;

    private User $teacher;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.co_teaching', false);
        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
        $this->student = $this->createUserWithRole(UserRole::Student, ['created_by' => $this->teacher->id]);
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/courses/{$this->course->id}/enroll")->assertStatus(201);
    }

    private function examIn(Course $course, User $owner, string $title): Exam
    {
        $exam = $this->makeExam($owner, $course, ['status' => 'published', 'title' => $title, 'duration_minutes' => 30, 'max_attempts' => 5, 'show_result_immediately' => true]);
        $this->addSingleChoiceQuestion($exam, ['points' => 2]);

        return $exam;
    }

    private function sit(Exam $exam, bool $correct): int
    {
        $attempt = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201)->json('data');
        $question = $attempt['questions'][0];
        $option = \App\Models\Question::findOrFail($question['id'])->options()->where('is_correct', $correct)->value('id');
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $question['id'], 'option_ids' => [$option]])->assertOk();
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();

        return $attempt['id'];
    }

    private function list(?User $as = null, array $query = [])
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$this->student->id}/attempts?".http_build_query($query));
    }

    public function test_attempts_are_grouped_by_exam_with_every_attempt_in_order(): void
    {
        $older = $this->examIn($this->course, $this->teacher, 'Unit 1');
        $newer = $this->examIn($this->course, $this->teacher, 'Unit 2');
        $zero = $this->sit($older, false);
        $full = $this->sit($older, true);
        $this->sit($newer, true);

        $rows = $this->list()->assertOk()->json('data.attempts');

        $this->assertSame(['Unit 2', 'Unit 1', 'Unit 1'], array_column($rows, 'exam_title'));
        $this->assertSame([1, 1, 2], array_column($rows, 'attempt_number'));
        $byId = collect($rows)->keyBy('attempt_id');
        $this->assertSame(0, $byId[$zero]['score'], 'A zero score is a real 0.');
        $this->assertSame(2, $byId[$full]['score']);
        $this->assertTrue($byId[$zero]['grades_published']);
        $this->assertSame('failed', $byId[$zero]['outcome']);
        $this->assertSame([
            'attempt_id', 'exam_id', 'exam_title', 'attempt_number', 'status', 'end_reason', 'started_at', 'submitted_at',
            'grades_published', 'score', 'percentage', 'outcome', 'integrity_status',
        ], array_keys($rows[0]));
    }

    public function test_pagination_and_exam_filter_run_on_the_server(): void
    {
        $exam = $this->examIn($this->course, $this->teacher, 'Unit 1');
        foreach (range(1, 3) as $i) {
            $this->sit($exam, true);
        }
        $other = $this->examIn($this->course, $this->teacher, 'Unit 2');
        $this->sit($other, true);

        $page = $this->list(null, ['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 2, 'total' => 4], $page->json('data.meta'));
        $this->assertCount(3, $this->list(null, ['exam_id' => $exam->id])->json('data.attempts'));
    }

    public function test_only_exams_in_the_viewers_scope_are_listed(): void
    {
        $mine = $this->examIn($this->course, $this->teacher, 'Mine');
        $this->sit($mine, true);

        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $otherCourse = $this->createCourse($otherTeacher, ['status' => 'published']);
        $this->actingAs($this->student, 'sanctum')->postJson("/api/v1/student/courses/{$otherCourse->id}/enroll")->assertStatus(201);
        $theirs = $this->examIn($otherCourse, $otherTeacher, 'Theirs');
        $this->sit($theirs, true);

        $this->assertSame(['Mine'], array_column($this->list()->json('data.attempts'), 'exam_title'));

        // An assistant of the other teacher may open the student, but only sees that teacher's exams.
        $theirAssistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $otherTeacher->id]);
        $this->assertSame(['Theirs'], array_column($this->list($theirAssistant)->json('data.attempts'), 'exam_title'));

        // The other teacher manages the student through their course, and sees only their exam.
        $this->assertSame(['Theirs'], array_column($this->list($otherTeacher)->json('data.attempts'), 'exam_title'));
        // A teacher with no link to the student is refused outright.
        $this->list($this->createUserWithRole(UserRole::Teacher))->assertStatus(403);
        // Students never reach the staff endpoint.
        $this->list($this->student)->assertStatus(403);

        // Admin sees everything; co-teaching shares every exam.
        $admin = $this->createUserWithRole(UserRole::Admin);
        $this->assertCount(2, $this->list($admin)->json('data.attempts'));
        Config::set('app.co_teaching', true);
        $this->assertCount(2, $this->list($this->teacher->fresh())->json('data.attempts'));
    }

    public function test_the_query_count_does_not_grow_with_the_page_size(): void
    {
        $exam = $this->examIn($this->course, $this->teacher, 'Unit');
        foreach (range(1, 4) as $i) {
            $this->sit($exam, true);
        }
        $count = function (int $perPage): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list($this->teacher->fresh(), ['per_page' => $perPage])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count(1), $count(4));
    }
}
