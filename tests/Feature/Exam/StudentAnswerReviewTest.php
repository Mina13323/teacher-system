<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\Question;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Essay feedback + post-publication answer review (P0.4).
 *
 * Lifecycle under test:
 *   student submits essay -> teacher grades + feedback -> NOT visible to the
 *   student -> teacher publishes -> score + feedback + answer key review become
 *   visible (when the exam allows review) -> never visible to other students.
 */
class StudentAnswerReviewTest extends ApiTestCase
{
    use InteractsWithExams;

    private function essayExamWithAttempt(array $examAttributes = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
            'show_result_immediately' => false,
        ], $examAttributes));

        $essay = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'essay',
            'points' => 5,
            'position' => 1,
            'reference_answer' => 'The ideal answer mentions chlorophyll.',
        ]);

        // A choice question alongside the essay so the option answer-key gate
        // is exercised too (its snapshot options carry is_correct).
        $this->addSingleChoiceQuestion($exam, ['points' => 2]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::query()
            ->where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $essay->id,
                'answer_text' => 'Chlorophyll absorbs light.',
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->assertSame(
            ExamAttemptStatus::Grading->value,
            $attempt->fresh()->status->value
        );

        return [$teacher, $student, $exam, $essay, $attempt->fresh()];
    }

    private function gradeEssay(array $ctx, int $points = 4, ?string $feedback = 'Good, add more detail.'): void
    {
        [$teacher, , , $essay, $attempt] = $ctx;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $essay->id,
                'awarded_points' => $points,
                'feedback' => $feedback,
            ])
            ->assertStatus(200);
    }

    public function test_feedback_is_stored_and_hidden_before_publication(): void
    {
        $ctx = $this->essayExamWithAttempt();
        $this->gradeEssay($ctx);
        [, $student, , , $attempt] = $ctx;

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200);

        $questions = collect($response->json('data.questions'));
        $essayQuestion = $questions->firstWhere('question_type', 'essay');
        $this->assertNull($essayQuestion['review']);
        // The student's own answer text remains visible while in review.
        $this->assertSame('Chlorophyll absorbs light.', $essayQuestion['answer_text']);

        // The answer key must not leak before publication (any question type).
        foreach ($questions as $question) {
            $this->assertNull($question['review']);
            foreach ($question['options'] as $option) {
                $this->assertNull($option['is_correct']);
            }
        }
    }

    public function test_feedback_and_review_visible_after_publication(): void
    {
        $ctx = $this->essayExamWithAttempt();
        $this->gradeEssay($ctx);
        [$teacher, $student, , $essay, $attempt] = $ctx;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200);

        $question = collect($response->json('data.questions'))->firstWhere('question_type', 'essay');
        $this->assertNotNull($question['review']);
        $this->assertSame('Good, add more detail.', $question['review']['feedback']);
        $this->assertSame(4, $question['review']['points_earned']);
        $this->assertSame('The ideal answer mentions chlorophyll.', $question['review']['explanation']);
        $this->assertSame('passed', $response->json('data.outcome')); // 4/5 = 80% >= 50 threshold
    }

    public function test_allow_answer_review_off_hides_review_after_publication(): void
    {
        $ctx = $this->essayExamWithAttempt(['allow_answer_review' => false]);
        $this->gradeEssay($ctx);
        [$teacher, $student, , , $attempt] = $ctx;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200);

        $questions = collect($response->json('data.questions'));
        foreach ($questions as $question) {
            $this->assertNull($question['review']);
            foreach ($question['options'] as $option) {
                $this->assertNull($option['is_correct']);
            }
        }
        // The score itself is still published.
        $this->assertSame(4, $response->json('data.score'));
    }

    public function test_another_student_cannot_see_the_attempt_or_feedback(): void
    {
        $ctx = $this->essayExamWithAttempt();
        $this->gradeEssay($ctx);
        [, , , , $attempt] = $ctx;

        $outsider = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(403);
    }

    public function test_teacher_feedback_belongs_to_the_correct_answer(): void
    {
        $ctx = $this->essayExamWithAttempt();
        $this->gradeEssay($ctx, points: 3, feedback: 'Feedback A');
        [, $student, , $essay, $attempt] = $ctx;

        $this->assertDatabaseHas('exam_answers', [
            'attempt_id' => $attempt->id,
            'question_id' => $essay->id,
            'feedback' => 'Feedback A',
            'points_earned' => 3,
        ]);
    }
}
