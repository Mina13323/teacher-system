<?php

namespace Tests\Feature\Exam;

use App\Enums\EnrollmentStatus;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;

/**
 * Regression coverage for the exam grading authorization boundary and the
 * publication gate.
 *
 * Original defect: the teacher route group carried only `auth:sanctum` (no role
 * middleware) and ExamAttemptPolicy::view() was satisfied by attempt ownership.
 * Together those allowed a student to read their own attempt through the staff
 * resource (which exposes correctness and unpublished scores) and then grade and
 * publish it themselves.
 *
 * The group now also carries `role:teacher|assistant|admin`, and grading uses
 * the dedicated viewStaff / grade / publishGrades abilities. Both layers must
 * hold: the middleware is a coarse boundary, the policy is the real check.
 */
class ExamGradingAuthorizationTest extends ApiTestCase
{
    /**
     * Build a teacher-owned published exam with one essay question and a
     * submitted (but unpublished) attempt for the given student.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Exam, 2: \App\Models\ExamAttempt, 3: \App\Models\Question}
     */
    private function makeSubmittedEssayAttempt(
        UserRole $ownerRole = UserRole::Student,
        bool $published = false,
    ): array {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => ExamStatus::Published,
        ]);

        $question = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => QuestionType::Essay->value,
            'points' => 20,
            'reference_answer' => 'Model answer that must never reach a student.',
        ]);

        $student = $this->createUserWithRole($ownerRole, ['can_take_exams' => true]);

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active->value,
            'enrolled_at' => now(),
        ]);

        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => ExamAttemptStatus::Submitted->value,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
            'score' => 20,
            'percentage' => 100,
            'grades_published_at' => $published ? now()->subMinute() : null,
        ]);

        ExamAttemptQuestion::create([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => QuestionType::Essay->value,
            'points' => 20,
            'position' => 1,
        ]);

        ExamAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'answer_text' => 'The student essay response.',
        ]);

        return [$student, $exam, $attempt, $question];
    }

    // -----------------------------------------------------------------
    // Staff-only grading endpoints
    // -----------------------------------------------------------------

    public function test_student_cannot_read_own_attempt_through_the_staff_endpoint(): void
    {
        [$student, , $attempt] = $this->makeSubmittedEssayAttempt();

        // Before the fix this returned 200 with score, percentage, is_correct
        // and feedback, because authorize('view') is satisfied by ownership.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(403);
    }

    public function test_student_cannot_grade_their_own_essay(): void
    {
        [$student, , $attempt, $question] = $this->makeSubmittedEssayAttempt();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 20,
                'feedback' => 'Self-awarded full marks.',
            ])
            ->assertStatus(403);

        $answer = ExamAnswer::where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $this->assertNull($answer->points_earned, 'Student must not be able to award themselves points.');
    }

    public function test_student_cannot_publish_their_own_grades(): void
    {
        [$student, , $attempt] = $this->makeSubmittedEssayAttempt();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(403);

        $this->assertNull(
            $attempt->fresh()->grades_published_at,
            'Student must not be able to publish their own grades.'
        );
    }

    public function test_student_cannot_read_another_students_attempt_through_the_staff_endpoint(): void
    {
        [, , $attempt] = $this->makeSubmittedEssayAttempt();
        $intruder = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(403);
    }

    public function test_teacher_who_does_not_manage_the_exam_cannot_grade_it(): void
    {
        [, , $attempt, $question] = $this->makeSubmittedEssayAttempt();
        $outsider = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 20,
            ])
            ->assertStatus(403);
    }

    public function test_owning_teacher_can_still_grade_and_publish(): void
    {
        Notification::fake();

        [$student, , $attempt, $question] = $this->makeSubmittedEssayAttempt();
        $teacher = $attempt->exam->creator;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 15,
                'feedback' => 'Strong answer, one point missing.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.answers.0.points_earned', 15);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200)
            ->assertJsonPath('data.grades_published', true);

        Notification::assertSentTo($student, ResultAvailableNotification::class);
    }

    public function test_assistant_can_grade_and_publish_as_operational_staff(): void
    {
        Notification::fake();

        [$student, , $attempt, $question] = $this->makeSubmittedEssayAttempt();
        $assistant = $this->createUserWithRole(UserRole::Assistant);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 12,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.answers.0.points_earned', 12);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200)
            ->assertJsonPath('data.grades_published', true);
    }

    public function test_essay_award_cannot_exceed_the_question_maximum(): void
    {
        [, , $attempt, $question] = $this->makeSubmittedEssayAttempt();
        $teacher = $attempt->exam->creator;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $question->id,
                'awarded_points' => 999,
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Publication gate on student-facing endpoints
    // -----------------------------------------------------------------

    public function test_unpublished_score_is_hidden_on_the_student_attempts_endpoint(): void
    {
        [$student, $exam, $attempt] = $this->makeSubmittedEssayAttempt();

        // The attempt carries score=20 / percentage=100 but grades are not
        // published, so the endpoint must not reveal them.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}/attempts")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $attempt->id)
            ->assertJsonPath('data.0.grades_published', false)
            ->assertJsonPath('data.0.score', null)
            ->assertJsonPath('data.0.percentage', null);
    }

    public function test_unpublished_score_is_hidden_in_exam_detail_my_attempts(): void
    {
        [$student, $exam] = $this->makeSubmittedEssayAttempt();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.my_attempts.0.grades_published', false)
            ->assertJsonPath('data.my_attempts.0.score', null)
            ->assertJsonPath('data.my_attempts.0.percentage', null);
    }

    public function test_published_score_is_visible_on_the_student_attempts_endpoint(): void
    {
        [$student, $exam] = $this->makeSubmittedEssayAttempt(published: true);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}/attempts")
            ->assertStatus(200)
            ->assertJsonPath('data.0.grades_published', true)
            ->assertJsonPath('data.0.score', 20)
            ->assertJsonPath('data.0.percentage', 100);
    }

    // -----------------------------------------------------------------
    // Publication idempotency
    // -----------------------------------------------------------------

    public function test_publishing_grades_twice_is_idempotent_and_notifies_once(): void
    {
        Notification::fake();

        [$student, , $attempt] = $this->makeSubmittedEssayAttempt();
        $teacher = $attempt->exam->creator;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200);

        $firstPublishedAt = $attempt->fresh()->grades_published_at;
        $this->assertNotNull($firstPublishedAt);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200);

        $after = $attempt->fresh();

        $this->assertEquals(
            $firstPublishedAt->toIso8601String(),
            $after->grades_published_at->toIso8601String(),
            'Re-publishing must not rewrite the original publication timestamp.'
        );

        Notification::assertSentToTimes($student, ResultAvailableNotification::class, 1);
    }
}
