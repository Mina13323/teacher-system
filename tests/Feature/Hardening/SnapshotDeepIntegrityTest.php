<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Phase 5.2 — Deep snapshot integrity. The attempt snapshot is fully
 * self-contained: the complete business operation (start -> answer -> submit ->
 * grade) must still work and produce the correct historical result even after a
 * teacher deletes the live question or live options mid-attempt.
 */
class SnapshotDeepIntegrityTest extends ApiTestCase
{
    use InteractsWithExams;

    protected function enrolledStudentWithPublishedExam(array $attrs = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $attrs);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $course, $exam, $teacher];
    }

    protected function start($student, $exam): ExamAttempt
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        return ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
    }

    public function test_full_business_operation_survives_live_question_deletion(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->start($student, $exam);
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);

        // Teacher deletes the live question (its live options cascade away).
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/questions/{$question->id}")
            ->assertStatus(200);

        // The student can still answer the frozen snapshot question.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        // Submit -> grade (against the snapshot) still succeeds end-to-end.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.passed', true);

        // Historical snapshot + grading bytes survive even though the live
        // question row is gone.
        $this->assertDatabaseHas('exam_attempt_questions', [
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
        ]);
        $this->assertSame('submitted', $attempt->fresh()->status->value);
    }

    public function test_wrong_option_answer_grades_correctly_after_live_option_deletion(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->start($student, $exam);
        $question = $exam->questions()->first();
        $wrong = $question->options()->where('is_correct', false)->first();

        // Teacher deletes the live (wrong) option. The snapshot still carries it.
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/options/{$wrong->id}")
            ->assertStatus(200);

        // Student selects the (now-deleted live) wrong option from the snapshot.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $wrong->id,
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.percentage', 0)
            ->assertJsonPath('data.passed', false);
    }

    public function test_attempt_answers_are_scoped_to_snapshot_not_live_after_option_flip(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->start($student, $exam);
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);
        $wrong = $question->options()->where('is_correct', false)->first();

        // Flip the live answer key.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$wrong->id}", ['is_correct' => true])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$correct->id}", ['is_correct' => false])
            ->assertStatus(200);

        // Student answers the ORIGINAL snapshot-correct option -> 100%.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.percentage', 100);
    }

    public function test_middle_of_exam_question_deletion_does_not_break_remaining_questions(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $this->addSingleChoiceQuestion($exam, ['question_text' => 'Second question']);
        $attempt = $this->start($student, $exam);

        $q1 = $exam->questions()->orderBy('position')->first();
        $q2 = $exam->questions()->orderBy('position')->skip(1)->first();

        // Delete the first live question mid-attempt.
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/questions/{$q1->id}")
            ->assertStatus(200);

        // Answer + submit still maps the second question correctly (no orphan).
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $q2->id,
                'option_id' => $this->correctOption($q2)->id,
            ])->assertStatus(200);

        // Grade: 1 point earned out of the total points of the frozen snapshot
        // (which still includes the deleted question).
        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->assertSame(1, $response->json('data.score'));
        $this->assertSame(50, $response->json('data.percentage'));
    }
}
