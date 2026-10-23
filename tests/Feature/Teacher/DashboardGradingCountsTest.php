<?php

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\BuildsEssayAttempts;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1-F: the teacher dashboard's grading work queue counts essay answers that
 * still need a grade (blank essays included, choice answers never), graded
 * zeros, attempts ready to publish and published attempts, with aggregate
 * queries only.
 */
class DashboardGradingCountsTest extends ApiTestCase
{
    use BuildsEssayAttempts, InteractsWithExams;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpEssayFixture();
    }

    private function as(User $student, $exam, array $texts, bool $submit = true): array
    {
        $attempt = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])->assertStatus(201)->json('data');
        // Answer the choice question too: its answer row never has graded_at.
        $choice = $this->choiceId($attempt);
        $option = \App\Models\Question::findOrFail($choice)->options()->value('id');
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $choice, 'option_ids' => [$option]])->assertOk();
        foreach ($texts as $i => $text) {
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $this->essayIds($attempt)[$i], 'answer_text' => $text])->assertOk();
        }
        if ($submit) {
            $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")->assertOk();
        }

        return $attempt;
    }

    private function student(): User
    {
        $s = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($s, 'sanctum')->postJson("/api/v1/student/courses/{$this->course->id}/enroll")->assertStatus(201);

        return $s;
    }

    private function dashboard()
    {
        return $this->actingAs($this->teacher->fresh(), 'sanctum')->getJson('/api/v1/teacher/dashboard')->assertOk();
    }

    public function test_the_counts_distinguish_ungraded_zero_ready_and_published(): void
    {
        $exam = $this->exam(2);
        $mcqOnly = $this->exam(0);

        $a = $this->as($this->student(), $exam, ['Written']);        // 1 written + 1 blank: 2 ungraded
        $b = $this->as($this->student(), $exam, ['x', 'y']);
        [$q1, $q2] = $this->essayIds($b);
        $this->grade($b, $q1, 0)->assertOk();                         // explicit zero
        $this->grade($b, $q2, 5)->assertOk();                         // b: fully graded -> ready
        $c = $this->as($this->student(), $exam, ['x', 'y']);
        $this->grade($c, $q1, 4)->assertOk();
        $this->grade($c, $q2, 4)->assertOk();
        $this->publish($c)->assertOk();                               // c: published
        $this->as($this->student(), $exam, ['still typing'], submit: false); // running: never counted
        $this->as($this->student(), $mcqOnly, []);                    // MCQ only: ready, no essays

        $d = $this->dashboard()->json('data');

        $this->assertSame(2, $d['pending_essay_grading']);
        $this->assertSame(1, $d['essays_graded_zero']);
        $this->assertSame(2, $d['attempts_ready_to_publish'], 'b and the MCQ-only attempt.');
        $this->assertSame(1, $d['attempts_published']);
        $this->assertSame([[
            'exam_id' => $exam->id,
            'title' => $exam->title,
            'ungraded' => 2,
            'url' => "/teacher/exams/{$exam->id}/grading?status=ungraded",
        ]], $d['grading_queue']);
        $this->assertSame(1, $d['active_attempts_count']);
    }

    public function test_counts_are_scoped_to_the_viewers_exams(): void
    {
        $exam = $this->exam(1);
        $this->as($this->student(), $exam, ['x']);

        $stranger = $this->createUserWithRole(UserRole::Teacher);
        \Illuminate\Support\Facades\Config::set('app.co_teaching', false);
        $d = $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/teacher/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $d['pending_essay_grading']);
        $this->assertSame([], $d['grading_queue']);
    }

    public function test_the_query_count_does_not_grow_with_attempts(): void
    {
        $exam = $this->exam(1);
        $this->as($this->student(), $exam, ['x']);
        $count = function (): int {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $before = $count();
        foreach (range(1, 3) as $i) {
            $this->as($this->student(), $exam, ['x']);
        }
        $this->assertSame($before, $count());
    }
}
