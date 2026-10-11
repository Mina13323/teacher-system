<?php

namespace Tests\Feature\Authorization;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Tests\Feature\ApiTestCase;

/**
 * P0-5: an assistant grades and publishes only within the teacher scope it
 * is allowed to manage. With co-teaching off, an assistant employed by teacher
 * A gets 403 on teacher B's attempts (view, grade, publish, list, regrade),
 * whatever permission it holds. With co-teaching on, staff share every
 * teacher's exams, as before. Owners and admins are unchanged.
 */
class AssistantGradingScopeTest extends ApiTestCase
{
    private User $teacherA;

    private User $assistantA;

    private User $teacherB;

    private Exam $examB;

    private Question $essayB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacherA = $this->createUserWithRole(UserRole::Teacher);
        $this->assistantA = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $this->teacherA->id]);
        $this->teacherB = $this->createUserWithRole(UserRole::Teacher);

        $courseB = $this->createCourse($this->teacherB, ['status' => 'published']);
        $this->examB = Exam::factory()->create([
            'course_id' => $courseB->id,
            'created_by' => $this->teacherB->id,
            'status' => ExamStatus::Published,
        ]);
        $this->essayB = Question::factory()->create([
            'exam_id' => $this->examB->id,
            'type' => QuestionType::Essay->value,
            'points' => 20,
        ]);
    }

    private function submittedAttemptB(): ExamAttempt
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $this->examB->id,
            'student_id' => $student->id,
            'status' => ExamAttemptStatus::Submitted,
            'pass_percentage' => 50,
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(20),
            'submitted_at' => now(),
        ]);
        ExamAttemptQuestion::create([
            'attempt_id' => $attempt->id,
            'question_id' => $this->essayB->id,
            'question_text' => $this->essayB->question_text,
            'question_type' => QuestionType::Essay->value,
            'points' => 20,
            'position' => 1,
        ]);
        ExamAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $this->essayB->id,
            'answer_text' => 'Essay.',
        ]);

        return $attempt;
    }

    /** @return array<string, int> status code per capability */
    private function capabilities(User $user, ExamAttempt $attempt): array
    {
        $api = $this->actingAs($user, 'sanctum');

        return [
            'view' => $api->getJson("/api/v1/teacher/attempts/{$attempt->id}")->status(),
            'list' => $api->getJson("/api/v1/teacher/exams/{$this->examB->id}/attempts")->status(),
            'grade' => $api->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $this->essayB->id,
                'awarded_points' => 12,
            ])->status(),
            'publish' => $api->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")->status(),
        ];
    }

    public function test_with_co_teaching_off_an_assistant_cannot_touch_another_teachers_attempts(): void
    {
        Config::set('app.co_teaching', false);
        $this->assertTrue($this->assistantA->hasPermissionTo('exams.view'), 'Holds the permission the old fallback trusted.');
        $attempt = $this->submittedAttemptB();

        $this->assertSame(
            ['view' => 403, 'list' => 403, 'grade' => 403, 'publish' => 403],
            $this->capabilities($this->assistantA, $attempt)
        );

        $row = ExamAttempt::findOrFail($attempt->id);
        $this->assertNull($row->grades_published_at);
        $this->assertNull(ExamAnswer::where('attempt_id', $attempt->id)->value('points_earned'));
    }

    public function test_with_co_teaching_off_an_assistant_cannot_regrade_another_teachers_question(): void
    {
        Config::set('app.co_teaching', false);
        $exam = Exam::factory()->create(['course_id' => $this->examB->course_id, 'created_by' => $this->teacherB->id]);
        $question = Question::factory()->create(['exam_id' => $exam->id, 'type' => QuestionType::SingleChoice->value]);

        $this->actingAs($this->assistantA, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(403);
    }

    public function test_with_co_teaching_off_the_assistant_keeps_its_own_teachers_attempts(): void
    {
        Config::set('app.co_teaching', false);
        $assistantB = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $this->teacherB->id]);
        $attempt = $this->submittedAttemptB();

        $this->assertSame(
            ['view' => 200, 'list' => 200, 'grade' => 200, 'publish' => 200],
            $this->capabilities($assistantB, $attempt)
        );
    }

    public function test_with_co_teaching_on_staff_share_every_teachers_attempts(): void
    {
        Config::set('app.co_teaching', true);
        $attempt = $this->submittedAttemptB();

        $this->assertSame(
            ['view' => 200, 'list' => 200, 'grade' => 200, 'publish' => 200],
            $this->capabilities($this->assistantA, $attempt)
        );
    }

    public function test_an_assistant_without_a_teacher_link_keeps_the_single_lms_scope(): void
    {
        Config::set('app.co_teaching', false);
        $unlinked = $this->createUserWithRole(UserRole::Assistant);
        $attempt = $this->submittedAttemptB();

        $this->assertSame(
            ['view' => 200, 'list' => 200, 'grade' => 200, 'publish' => 200],
            $this->capabilities($unlinked, $attempt)
        );
    }

    public function test_owner_and_admin_are_unchanged_and_other_teachers_stay_out(): void
    {
        foreach ([false, true] as $coTeaching) {
            Config::set('app.co_teaching', $coTeaching);

            $this->assertSame(
                ['view' => 200, 'list' => 200, 'grade' => 200, 'publish' => 200],
                $this->capabilities($this->teacherB, $this->submittedAttemptB())
            );

            $admin = $this->createUserWithRole(UserRole::Admin);
            $this->assertSame(
                ['view' => 200, 'list' => 200, 'grade' => 200, 'publish' => 200],
                $this->capabilities($admin, $this->submittedAttemptB())
            );
        }

        Config::set('app.co_teaching', false);
        $this->assertSame(
            ['view' => 403, 'list' => 403, 'grade' => 403, 'publish' => 403],
            $this->capabilities($this->teacherA, $this->submittedAttemptB())
        );
    }

    public function test_a_student_can_never_reach_the_staff_endpoints(): void
    {
        $attempt = $this->submittedAttemptB();
        $owner = User::findOrFail($attempt->student_id);

        foreach ([false, true] as $coTeaching) {
            Config::set('app.co_teaching', $coTeaching);
            $this->assertSame(
                ['view' => 403, 'list' => 403, 'grade' => 403, 'publish' => 403],
                $this->capabilities($owner, $attempt)
            );
        }
    }
}
