<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Regression tests for Phase 3.1 snapshot integrity: teacher edits/deletions of
 * live exam content must never corrupt an existing (in-progress or submitted)
 * attempt. The attempt snapshot is a self-contained historical representation.
 */
class ExamSnapshotIntegrityTest extends ApiTestCase
{
    use InteractsWithExams;

    /**
     * Build an enrolled student plus a published single-question exam.
     */
    private function enrolledStudentWithPublishedExam(array $examAttributes = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $examAttributes);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $course, $exam, $teacher];
    }

    private function startAttempt($student, $exam): ExamAttempt
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        return ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
    }

    public function test_snapshot_survives_live_question_deletion(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $question = $exam->questions()->first();

        // Teacher deletes the live question. Its live options cascade away,
        // but the attempt's frozen snapshot must remain intact.
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/questions/{$question->id}")
            ->assertStatus(200);

        // Historical snapshot rows still exist.
        $this->assertDatabaseHas('exam_attempt_questions', [
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
        ]);

        $snapshotQuestion = ExamAttemptQuestion::where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->first();

        $this->assertSame(2, $snapshotQuestion->attemptOptions()->count());

        // The attempt is still viewable by the student and shows the frozen text.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.questions')
            ->assertJsonPath('data.questions.0.question_text', $snapshotQuestion->question_text);
    }

    public function test_student_can_still_answer_snapshot_question_after_live_question_deletion(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/questions/{$question->id}")
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        $this->assertDatabaseHas('exam_answers', [
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'option_id' => $correct->id,
        ]);
    }

    public function test_snapshot_survives_live_option_deletion(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $question = $exam->questions()->first();
        $wrong = $question->options()->where('is_correct', false)->first();

        // Teacher deletes a live option. The snapshot must still carry it.
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/options/{$wrong->id}")
            ->assertStatus(200);

        $this->assertStillAnswerableAndGradeable($student, $attempt, $exam);
    }

    public function test_snapshot_retains_original_question_payload_after_live_edit(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $question = $exam->questions()->first();
        $originalText = $question->question_text;

        // Teacher edits the live question text and points.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/questions/{$question->id}", [
                'question_text' => 'Edited question text',
                'points' => 99,
            ])->assertStatus(200);

        $snapshotQuestion = ExamAttemptQuestion::where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->first();

        // Attempt still uses the ORIGINAL text and points.
        $this->assertSame($originalText, $snapshotQuestion->question_text);
        $this->assertSame(1, $snapshotQuestion->points);

        // And the student view reflects the snapshot, not the live edit.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.questions.0.question_text', $originalText)
            ->assertJsonPath('data.questions.0.points', 1);
    }

    public function test_snapshot_retains_original_correct_option_after_live_change(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);
        $wrong = $question->options()->where('is_correct', false)->first();

        // Teacher flips the correct option on the live question.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$wrong->id}", ['is_correct' => true])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$correct->id}", ['is_correct' => false])
            ->assertStatus(200);

        // Student answers the ORIGINAL correct option.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        // Submit and grade against the snapshot (original correct = the answer
        // the student chose) -> 100%.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.passed', true);
    }

    public function test_grading_uses_snapshot_not_live_after_teacher_changes(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question);

        // Student answers the snapshot-correct option.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        // Teacher changes points and flips the correct option on the live exam.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/questions/{$question->id}", ['points' => 10])
            ->assertStatus(200);

        $wrong = $question->fresh()->options()->where('is_correct', false)->first();
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$wrong->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.score', 1);
    }

    public function test_attempt_uses_pass_percentage_frozen_at_start(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam(['pass_percentage' => 60]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        $this->assertSame(60, $attempt->pass_percentage);

        // Teacher raises the live pass threshold.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", ['pass_percentage' => 80])
            ->assertStatus(200);

        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(200);

        // 100% >= frozen 60 -> pass, even though the live threshold is now 80.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.passed', true);
    }

    public function test_teacher_attempt_detail_passed_uses_frozen_threshold(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam(['pass_percentage' => 60]);
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}", ['pass_percentage' => 80])
            ->assertStatus(200);

        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.pass_percentage', 60)
            ->assertJsonPath('data.passed', true);
    }

    public function test_snapshot_position_unchanged_by_reorder(): void
    {
        [$student, , $exam, $teacher] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);
        $q1 = $exam->questions()->first();

        // Add a second question and reorder via position updates so the first is
        // no longer first in the live exam.
        $q2 = $this->addSingleChoiceQuestion($exam, ['question_text' => 'Second question']);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/questions/{$q2->id}", ['position' => 1])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/questions/{$q1->id}", ['position' => 2])
            ->assertStatus(200);

        // The attempt snapshot keeps the original order (only one question).
        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}");

        $response->assertStatus(200)->assertJsonCount(1, 'data.questions');
        $this->assertSame($q1->question_text, $response->json('data.questions.0.question_text'));
    }

    public function test_new_attempt_allowed_after_previous_submitted(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['max_attempts' => 2]);
        $first = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$first->id}/submit")
            ->assertStatus(200);

        $this->assertNull($first->fresh()->active_key);

        $second = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $this->assertNotSame($first->id, $second->json('data.id'));
        $this->assertSame(2, $second->json('data.attempt_number'));

        $secondAttempt = ExamAttempt::findOrFail($second->json('data.id'));
        $this->assertSame($student->id.':'.$exam->id, $secondAttempt->active_key);
    }

    public function test_snapshot_not_created_without_active_key_constraint(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['max_attempts' => 2]);
        $attempt = $this->startAttempt($student, $exam);

        // The in-progress attempt must carry the active key.
        $this->assertSame($student->id.':'.$exam->id, $attempt->fresh()->active_key);
    }

    public function test_submitted_attempt_cannot_be_mutated(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // Trying to save an answer to a submitted attempt is rejected.
        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(422);

        // And it must not have returned to in_progress.
        $this->assertSame('submitted', $attempt->fresh()->status->value);
    }

    public function test_expired_attempt_cannot_be_mutated(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithPublishedExam(['duration_minutes' => 1]);
        $attempt = $this->startAttempt($student, $exam);

        $attempt->expires_at = now()->subMinute();
        $attempt->save();

        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(422);

        $this->assertSame('expired', $attempt->fresh()->status->value);
    }

    /**
     * After a live option is deleted, the student can still answer and grade
     * against the frozen snapshot.
     */
    private function assertStillAnswerableAndGradeable($student, ExamAttempt $attempt, $exam): void
    {
        $question = $exam->questions()->first();
        $correct = $this->correctOption($question->fresh());

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $correct->id,
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.percentage', 100);
    }
}
