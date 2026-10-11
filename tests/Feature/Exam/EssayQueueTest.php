<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\BuildsEssayAttempts;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1-B: GET /teacher/exams/{exam}/essay-answers — the grading queue.
 */
class EssayQueueTest extends ApiTestCase
{
    use BuildsEssayAttempts, InteractsWithExams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEssayFixture();
    }

    private function enrolledStudent(string $name, string $code): User
    {
        $student = $this->createUserWithRole(UserRole::Student, ['name' => $name, 'student_code' => $code]);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$this->course->id}/enroll")->assertStatus(201);

        return $student;
    }

    /** Start (and optionally answer/submit) an attempt as the given student. */
    private function attemptAs(User $student, $exam, array $texts = [], bool $submit = true): array
    {
        $attempt = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)->json('data');
        foreach ($texts as $i => $text) {
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $this->essayIds($attempt)[$i], 'answer_text' => $text])
                ->assertOk();
        }
        if ($submit) {
            $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();
        }

        return $attempt;
    }

    private function queue($exam, array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/essay-answers?".http_build_query($query));
    }

    public function test_only_essays_of_handed_in_attempts_are_queued_and_blank_essays_are_ungraded(): void
    {
        $exam = $this->exam(2);
        $amira = $this->enrolledStudent('Amira', 'S-001');
        $bilal = $this->enrolledStudent('Bilal', 'S-002');
        $done = $this->attemptAs($amira, $exam, ["Paragraph one.\n\nParagraph two."]);
        $this->attemptAs($bilal, $exam, ['Still typing'], submit: false);

        $data = $this->queue($exam)->assertOk()->json('data.answers');

        $this->assertCount(2, $data, 'Two essay questions of the one handed-in attempt; the running attempt is not queued.');
        $this->assertSame([$done['id'], $done['id']], array_column($data, 'attempt_id'));
        [$written, $blank] = $data;
        $this->assertSame("Paragraph one.\n\nParagraph two.", $written['answer_text']);
        $this->assertTrue($written['has_answer']);
        $this->assertFalse($blank['has_answer']);
        $this->assertNull($blank['answer_text']);
        foreach ($data as $row) {
            $this->assertNull($row['points_earned']);
            $this->assertSame('ungraded', $row['grading_status']);
            $this->assertSame(10, $row['max_points']);
        }

        $this->assertSame([
            'attempt_id', 'attempt_number', 'attempt_status', 'grades_published', 'question_id', 'question_text',
            'max_points', 'position', 'student', 'has_answer', 'answer_text', 'points_earned', 'feedback',
            'graded_at', 'grade_version', 'grading_status',
        ], array_keys($written));
        $this->assertSame(['id', 'name', 'student_code'], array_keys($written['student']));
    }

    public function test_strictly_expired_and_deleted_attempts_are_not_queued(): void
    {
        $strict = $this->exam(1, ['expiry_mode' => 'expire']);
        $a = $this->attemptAs($this->student, $strict, ['Text'], submit: false);
        $this->travelTo(ExamAttempt::findOrFail($a['id'])->expires_at->copy()->addSeconds(5));
        $this->artisan('attempts:process-expired')->assertSuccessful();
        $this->assertSame([], $this->queue($strict)->assertOk()->json('data.answers'));
        $this->travelBack();

        $exam = $this->exam(1);
        $b = $this->attemptAs($this->student, $exam, ['Text']);
        ExamAttempt::findOrFail($b['id'])->delete();
        $this->assertSame([], $this->queue($exam)->assertOk()->json('data.answers'));
    }

    public function test_status_question_publication_and_search_filters_run_on_the_server(): void
    {
        $exam = $this->exam(2);
        $amira = $this->enrolledStudent('Amira Saleh', 'S-001');
        $bilal = $this->enrolledStudent('Bilal Omar', 'S-002');
        $a = $this->attemptAs($amira, $exam, ['A1', 'A2']);
        $b = $this->attemptAs($bilal, $exam, ['B1']);
        [$q1, $q2] = $this->essayIds($a);

        $this->grade($a, $q1, 0)->assertOk();
        $this->grade($a, $q2, 10)->assertOk();
        $this->publish($a)->assertOk();
        $this->grade($b, $q1, 4)->assertOk();

        $this->assertCount(1, $this->queue($exam, ['status' => 'ungraded'])->json('data.answers'), 'Only Bilal\'s blank q2.');
        $graded = $this->queue($exam, ['status' => 'graded'])->json('data.answers');
        $this->assertCount(3, $graded);
        $this->assertContains(0, array_column($graded, 'points_earned'), 'An explicit zero is graded.');
        $this->assertCount(2, $this->queue($exam, ['question_id' => $q1])->json('data.answers'));
        $this->assertCount(2, $this->queue($exam, ['publication_status' => 'published'])->json('data.answers'));
        $this->assertCount(2, $this->queue($exam, ['publication_status' => 'unpublished'])->json('data.answers'));
        $this->assertCount(2, $this->queue($exam, ['search' => 'bilal'])->json('data.answers'));
        $this->assertCount(2, $this->queue($exam, ['search' => 'S-001'])->json('data.answers'));
        $this->assertCount(0, $this->queue($exam, ['search' => '%'])->json('data.answers'), 'Wildcards are literal.');
        $this->assertCount(2, $this->queue($exam, ['attempt_id' => $b['id']])->json('data.answers'));
        $this->assertCount(2, $this->queue($exam, ['student_id' => $amira->id])->json('data.answers'));
    }

    public function test_pagination_is_server_side_and_capped(): void
    {
        $exam = $this->exam(1);
        foreach (range(1, 5) as $i) {
            $this->attemptAs($this->enrolledStudent("Student {$i}", "C-{$i}"), $exam, ["Answer {$i}"]);
        }

        $page = $this->queue($exam, ['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertCount(2, $page->json('data.answers'));
        $this->assertSame(['current_page' => 2, 'last_page' => 3, 'per_page' => 2, 'total' => 5], $page->json('data.meta'));
        $this->assertSame('Student 3', $page->json('data.answers.0.student.name'));
        $this->queue($exam, ['per_page' => 51])->assertStatus(422);
    }

    public function test_the_summary_counts_ungraded_graded_zero_and_publication_states(): void
    {
        $exam = $this->exam(2);
        $a = $this->attemptAs($this->enrolledStudent('A', 'A'), $exam, ['x', 'y']);
        $b = $this->attemptAs($this->enrolledStudent('B', 'B'), $exam, ['x']);
        $c = $this->attemptAs($this->enrolledStudent('C', 'C'), $exam, ['x', 'y']);
        [$q1, $q2] = $this->essayIds($a);
        $this->grade($a, $q1, 0)->assertOk();
        $this->grade($a, $q2, 7)->assertOk();          // a: fully graded, unpublished
        $this->grade($c, $q1, 5)->assertOk();
        $this->grade($c, $q2, 5)->assertOk();
        $this->publish($c)->assertOk();                 // c: published
        // b: two ungraded (one blank)

        $summary = $this->queue($exam, ['per_page' => 1])->json('data.summary');

        $this->assertSame(2, $summary['ungraded']);
        $this->assertSame(4, $summary['graded']);
        $this->assertSame(['ready_to_publish' => 1, 'awaiting_grading' => 1, 'published' => 1], $summary['attempts']);
        $this->assertSame([$q1, $q2], array_column($summary['questions'], 'question_id'));
        $this->assertSame(['ungraded' => 1, 'graded' => 2, 'graded_zero' => 1],
            array_intersect_key($summary['questions'][0], array_flip(['ungraded', 'graded', 'graded_zero'])));
        $this->assertArrayNotHasKey('summary', $this->queue($exam, ['include_summary' => 0])->json('data'));
    }

    public function test_the_grade_version_matches_the_save_contract(): void
    {
        $exam = $this->exam(1);
        $a = $this->attemptAs($this->student, $exam, ['x']);
        $row = $this->queue($exam)->json('data.answers.0');

        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$a['id']}/grade-essay", [
                'question_id' => $row['question_id'], 'awarded_points' => 3,
                'expected_version' => $row['grade_version'], 'compact_response' => true,
            ])->assertOk();

        $after = $this->queue($exam)->json('data.answers.0');
        $this->assertSame(3, $after['points_earned']);
        $detail = collect($this->actingAs($this->teacher, 'sanctum')->getJson("/api/v1/teacher/attempts/{$a['id']}")->json('data.questions'))
            ->firstWhere('id', $row['question_id']);
        $this->assertSame($detail['grade_version'], $after['grade_version']);
    }

    public function test_the_queue_is_scoped_to_the_exam_and_to_authorized_staff(): void
    {
        $exam = $this->exam(1);
        $other = $this->exam(1);
        $mine = $this->attemptAs($this->student, $exam, ['mine']);
        $theirs = $this->attemptAs($this->enrolledStudent('Other', 'O'), $other, ['secret text']);

        // Filters cannot reach another exam's attempts or students.
        $this->assertSame([], $this->queue($exam, ['attempt_id' => $theirs['id']])->json('data.answers'));
        $this->assertSame([], $this->queue($exam, ['student_id' => ExamAttempt::find($theirs['id'])->student_id])->json('data.answers'));

        // Students never reach it; another teacher (co-teaching off) neither.
        $this->queue($exam, [], $this->student)->assertStatus(403);
        Config::set('app.co_teaching', false);
        $stranger = $this->createUserWithRole(UserRole::Teacher);
        $this->queue($exam, [], $stranger)->assertStatus(403);
        $foreignAssistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $stranger->id]);
        $this->queue($exam, [], $foreignAssistant)->assertStatus(403);
        $ownAssistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $this->teacher->id]);
        $this->queue($exam, [], $ownAssistant)->assertOk()->assertJsonPath('data.answers.0.attempt_id', $mine['id']);
        Config::set('app.co_teaching', true);
        $this->queue($exam, [], $foreignAssistant)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/teacher/exams/{$exam->id}/essay-answers")->assertStatus(401);
    }

    public function test_the_query_count_does_not_grow_with_the_page_size(): void
    {
        $exam = $this->exam(2);
        foreach (range(1, 12) as $i) {
            $this->attemptAs($this->enrolledStudent("S{$i}", "C{$i}"), $exam, ["Answer {$i}"]);
        }

        $count = function (array $query) use ($exam): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            // A fresh user each time, so role/permission loading is counted alike.
            $this->queue($exam, $query, $this->teacher->fresh())->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $small = $count(['per_page' => 2]);
        $large = $count(['per_page' => 24]);
        $pageOnly = $count(['per_page' => 24, 'include_summary' => 0]);

        $this->assertSame($small, $large, 'Query count must not depend on the page size.');
        // exam + roles + permissions + count + page + 2 summary aggregates.
        $this->assertLessThanOrEqual(8, $large);
        $this->assertSame($large - 2, $pageOnly, 'The summary is exactly two aggregate queries.');
        fwrite(STDERR, "\n[budget] essay queue page: {$large} queries total (with summary), {$pageOnly} without summary\n");
    }
}
