<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\Question;
use App\Notifications\ResultAvailableNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class RegradeQuestionAttemptsTest extends ApiTestCase
{
    use InteractsWithExams;

    private function singleChoiceFixture(array $examAttributes = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $examAttributes);
        $question = $exam->questions()->firstOrFail();
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$teacher, $student, $course, $exam, $question];
    }

    private function enrollStudent($student, $course): void
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);
    }

    private function startAttempt($student, $exam): ExamAttempt
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201);

        return ExamAttempt::query()
            ->where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->firstOrFail();
    }

    public function test_teacher_can_regrade_a_published_single_choice_attempt_after_confirming_a_corrected_key(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $correct = $this->correctOption($question);
        $wrong = $question->options()->where('is_correct', false)->firstOrFail();
        $attempt = $this->startAttempt($student, $exam);
        $attemptQuestion = $attempt->attemptQuestions()->where('question_id', $question->id)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $wrong->id,
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);

        $before = $attempt->fresh();
        $this->assertNotNull($before->grades_published_at, 'This exam publishes choice results immediately.');
        $publishedAt = $before->grades_published_at->toIso8601String();
        $scoredAt = $before->scored_at->toIso8601String();

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_count', 1);

        // Correct the live key in the same way the teacher currently edits it.
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$correct->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$wrong->id}", ['is_correct' => true])
            ->assertStatus(200);

        // Regular key edits remain future-only until the teacher explicitly
        // confirms the regrade action.
        $snapshotWrong = ExamAttemptOption::query()
            ->where('attempt_question_id', $attemptQuestion->id)
            ->where('option_id', $wrong->id)
            ->firstOrFail();
        $this->assertFalse($snapshotWrong->is_correct);
        $this->assertSame(0, $before->score);

        Notification::fake();
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", [
                'confirmed' => true,
                'reason' => 'Corrected the answer key after review.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1)
            ->assertJsonPath('data.published_scores_changed', 1);

        $after = $attempt->fresh();
        $answer = ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $this->assertSame(1, $after->score);
        $this->assertSame(100, $after->percentage);
        $this->assertSame(ExamAttemptStatus::Published, $after->status);
        $this->assertSame($publishedAt, $after->grades_published_at->toIso8601String());
        $this->assertSame($scoredAt, $after->scored_at->toIso8601String());
        $this->assertTrue($answer->is_correct);
        $this->assertSame(1, $answer->points_earned);
        $this->assertTrue($snapshotWrong->fresh()->is_correct);

        Notification::assertSentToTimes($student, ResultAvailableNotification::class, 1);

        $questionAudit = AuditLog::query()
            ->where('action', 'exam.answer_key_regraded')
            ->where('target_id', $question->id)
            ->firstOrFail();
        $this->assertSame('Corrected the answer key after review.', $questionAudit->metadata['reason']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'grade.answer_key_regraded',
            'target_id' => $attempt->id,
        ]);
    }

    public function test_regrade_uses_exact_set_matching_for_multiple_choice_answers(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => true,
        ]);
        $multi = $this->addMultipleChoiceQuestion($exam);
        $question = $multi['question'];
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        // Freeze the incorrect key [correct 1, wrong 1] into the new attempt;
        // the student's intended selection is [correct 1, correct 2].
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$multi['correct'][1]->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$multi['wrong'][0]->id}", ['is_correct' => true])
            ->assertStatus(200);

        $attempt = $this->startAttempt($student, $exam);

        $intendedSelection = [$multi['correct'][0]->id, $multi['correct'][1]->id];
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_ids' => $intendedSelection,
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$multi['wrong'][0]->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$multi['correct'][1]->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1);

        $answer = ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->firstOrFail();
        $this->assertTrue($answer->is_correct);
        $this->assertSame(2, $answer->points_earned);
        $this->assertSame(2, $attempt->fresh()->score);

        $selectedIds = $answer->selectedOptionIds();
        sort($intendedSelection);
        $this->assertSame($intendedSelection, $selectedIds);
    }

    public function test_regrade_leaves_in_progress_and_expired_attempts_and_deadlines_untouched(): void
    {
        [$teacher, $student, $course, $exam, $question] = $this->singleChoiceFixture();
        $inProgress = $this->startAttempt($student, $exam);
        $originalCorrect = $this->correctOption($question);
        $wrong = $question->options()->where('is_correct', false)->firstOrFail();
        $inProgressQuestion = $inProgress->attemptQuestions()->where('question_id', $question->id)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$inProgress->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $originalCorrect->id,
            ])
            ->assertStatus(200);
        $inProgressDeadline = $inProgress->fresh()->expires_at->toIso8601String();

        $expiredStudent = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($expiredStudent, $course);
        $expired = $this->startAttempt($expiredStudent, $exam);
        $expiredQuestion = $expired->attemptQuestions()->where('question_id', $question->id)->firstOrFail();
        $expired->status = ExamAttemptStatus::Expired;
        $expired->expires_at = now()->subMinute();
        $expired->save();
        $expiredDeadline = $expired->fresh()->expires_at->toIso8601String();

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$originalCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$wrong->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 0)
            ->assertJsonPath('data.scores_changed', 0);

        $inProgressAfter = $inProgress->fresh();
        $expiredAfter = $expired->fresh();
        $this->assertSame(ExamAttemptStatus::InProgress, $inProgressAfter->status);
        $this->assertSame($inProgressDeadline, $inProgressAfter->expires_at->toIso8601String());
        $this->assertSame(ExamAttemptStatus::Expired, $expiredAfter->status);
        $this->assertSame($expiredDeadline, $expiredAfter->expires_at->toIso8601String());
        $this->assertFalse(ExamAttemptOption::query()
            ->where('attempt_question_id', $inProgressQuestion->id)
            ->where('option_id', $wrong->id)
            ->firstOrFail()->is_correct);
        $this->assertFalse(ExamAttemptOption::query()
            ->where('attempt_question_id', $expiredQuestion->id)
            ->where('option_id', $wrong->id)
            ->firstOrFail()->is_correct);
        $this->assertDatabaseHas('exam_answers', [
            'attempt_id' => $inProgress->id,
            'question_id' => $question->id,
            'option_id' => $originalCorrect->id,
        ]);
    }

    public function test_regrade_preserves_manually_awarded_essay_points_and_publication_time(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => false,
        ]);
        $question = $this->addSingleChoiceQuestion($exam, ['points' => 2]);
        $essay = Question::factory()->create([
            'exam_id' => $exam->id,
            'question_text' => 'Explain your reasoning.',
            'type' => QuestionType::Essay->value,
            'points' => 3,
            'position' => 2,
        ]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $attempt = $this->startAttempt($student, $exam);
        $correct = $this->correctOption($question);
        $wrong = $question->options()->where('is_correct', false)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $wrong->id,
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $essay->id,
                'answer_text' => 'A saved essay answer.',
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $essay->id,
                'awarded_points' => 2,
                'feedback' => 'Good explanation.',
            ])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200);

        $before = $attempt->fresh();
        $publishedAt = $before->grades_published_at->toIso8601String();
        $scoredAt = $before->scored_at->toIso8601String();
        $essayAnswerBefore = ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $essay->id)
            ->firstOrFail();
        $essayGradedAt = $essayAnswerBefore->graded_at->toIso8601String();

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$correct->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$wrong->id}", ['is_correct' => true])
            ->assertStatus(200);

        Notification::fake();
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1);

        $after = $attempt->fresh();
        $essayAnswerAfter = $essayAnswerBefore->fresh();
        $this->assertSame(4, $after->score);
        $this->assertSame(80, $after->percentage);
        $this->assertSame(ExamAttemptStatus::Published, $after->status);
        $this->assertSame($publishedAt, $after->grades_published_at->toIso8601String());
        $this->assertSame($scoredAt, $after->scored_at->toIso8601String());
        $this->assertSame(2, $essayAnswerAfter->points_earned);
        $this->assertSame('A saved essay answer.', $essayAnswerAfter->answer_text);
        $this->assertSame('Good explanation.', $essayAnswerAfter->feedback);
        $this->assertSame($essayGradedAt, $essayAnswerAfter->graded_at->toIso8601String());
        Notification::assertSentToTimes($student, ResultAvailableNotification::class, 1);
    }

    public function test_endpoint_requires_confirmation_valid_key_and_question_management_permission(): void
    {
        [$teacher, , , , $question] = $this->singleChoiceFixture();
        $correct = $this->correctOption($question);
        $outsider = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(403);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts")
            ->assertStatus(422);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$correct->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('correct_option_ids');
    }
}
