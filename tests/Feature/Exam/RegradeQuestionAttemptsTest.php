<?php

namespace Tests\Feature\Exam;

use App\Enums\AttemptOutcome;
use App\Enums\ExamAttemptStatus;
use App\Enums\IntegrityReviewDecision;
use App\Enums\IntegrityStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamIntegrityReview;
use App\Models\Option;
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

    /**
     * Requirement 1:
     * Published attempt + old correct answer + student selected old correct answer.
     * Change correct answer. Regrade.
     * Assert student's answer remains unchanged.
     * Assert answer-level grading changes to incorrect.
     * Assert awarded points change correctly.
     * Assert attempt total changes.
     * Assert percentage changes.
     * Assert published result changes.
     */
    public function test_regrade_published_attempt_where_student_selected_old_correct_answer(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();
        $attempt = $this->startAttempt($student, $exam);

        // Student selects the OLD correct option
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $oldCorrect->id,
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 1);

        // Verify initial student review state (published immediately)
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.outcome', 'passed')
            ->assertJsonPath('data.questions.0.review.is_correct', true)
            ->assertJsonPath('data.questions.0.review.points_earned', 1);

        // Teacher changes answer key: oldCorrect becomes false, newCorrect becomes true
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        Notification::fake();

        // Teacher runs regrade
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", [
                'confirmed' => true,
                'reason' => 'Changed correct answer from Nigeria to Russia.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1)
            ->assertJsonPath('data.published_scores_changed', 1);

        // 1. Assert student's answer remains unchanged
        $answer = ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->firstOrFail();
        $this->assertSame($oldCorrect->id, $answer->option_id, 'Student answer must remain immutable.');

        // 2. Assert answer-level grading changes to incorrect
        $this->assertFalse($answer->is_correct);

        // 3. Assert awarded points change correctly
        $this->assertSame(0, $answer->points_earned);

        // 4. Assert attempt total changes
        $fresh = $attempt->fresh();
        $this->assertSame(0, $fresh->score);

        // 5. Assert percentage changes
        $this->assertSame(0, $fresh->percentage);

        // 6. Assert published result changes immediately for student
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.percentage', 0)
            ->assertJsonPath('data.outcome', 'failed')
            ->assertJsonPath('data.questions.0.review.is_correct', false)
            ->assertJsonPath('data.questions.0.review.points_earned', 0);

        Notification::assertSentToTimes($student, ResultAvailableNotification::class, 1);

        // Assert audit log preserves metadata
        $questionAudit = AuditLog::query()
            ->where('action', 'exam.answer_key_regraded')
            ->where('target_id', $question->id)
            ->firstOrFail();
        $this->assertSame('Changed correct answer from Nigeria to Russia.', $questionAudit->metadata['reason']);
        $this->assertSame((string) $oldCorrect->id, (string) $questionAudit->metadata['old_correct_option_ids']);
        $this->assertSame((string) $newCorrect->id, (string) $questionAudit->metadata['new_correct_option_ids']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'grade.answer_key_regraded',
            'target_id' => $attempt->id,
        ]);
    }

    /**
     * Requirement 2:
     * Published attempt where the student selected the NEW correct answer.
     * Regrade. Assert it becomes correct.
     */
    public function test_regrade_published_attempt_where_student_selected_new_correct_answer(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();
        $attempt = $this->startAttempt($student, $exam);

        // Student selected the option that is initially marked incorrect
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $newCorrect->id,
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);

        // Teacher updates options so newCorrect is now true
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        // Regrade
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1);

        $answer = ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $this->assertSame($newCorrect->id, $answer->option_id);
        $this->assertTrue($answer->is_correct);
        $this->assertSame(1, $answer->points_earned);
        $this->assertSame(1, $attempt->fresh()->score);
        $this->assertSame(100, $attempt->fresh()->percentage);
    }

    /**
     * Requirement 3:
     * Attempt with multiple questions.
     * Change one question's answer key.
     * Assert only that question's grading changes and the final total is recalculated correctly.
     */
    public function test_regrade_attempt_with_multiple_questions_only_changes_target_question(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => true,
        ]);
        $q1 = $this->addSingleChoiceQuestion($exam, ['points' => 2]);
        $q2 = $this->addSingleChoiceQuestion($exam, ['points' => 3]);
        $q3 = $this->addSingleChoiceQuestion($exam, ['points' => 5]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $attempt = $this->startAttempt($student, $exam);

        $q1Correct = $this->correctOption($q1);
        $q2OldCorrect = $this->correctOption($q2);
        $q2NewCorrect = $q2->options()->where('is_correct', false)->firstOrFail();
        $q3Wrong = $q3->options()->where('is_correct', false)->firstOrFail();

        // Student answers: Q1 correct (2), Q2 old correct (3), Q3 wrong (0). Total = 5/10.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", ['question_id' => $q1->id, 'option_id' => $q1Correct->id])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", ['question_id' => $q2->id, 'option_id' => $q2OldCorrect->id])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", ['question_id' => $q3->id, 'option_id' => $q3Wrong->id])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 5);

        // Teacher updates Q2 only
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$q2OldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$q2NewCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        // Regrade Q2
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$q2->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);

        $fresh = $attempt->fresh();
        // Q1 is still 2 points
        $ans1 = ExamAnswer::query()->where('attempt_id', $attempt->id)->where('question_id', $q1->id)->firstOrFail();
        $this->assertTrue($ans1->is_correct);
        $this->assertSame(2, $ans1->points_earned);

        // Q2 is now 0 points
        $ans2 = ExamAnswer::query()->where('attempt_id', $attempt->id)->where('question_id', $q2->id)->firstOrFail();
        $this->assertFalse($ans2->is_correct);
        $this->assertSame(0, $ans2->points_earned);

        // Q3 is still 0 points
        $ans3 = ExamAnswer::query()->where('attempt_id', $attempt->id)->where('question_id', $q3->id)->firstOrFail();
        $this->assertFalse($ans3->is_correct);
        $this->assertSame(0, $ans3->points_earned);

        // Final score recalculated: 2 + 0 + 0 = 2 / 10 = 20%
        $this->assertSame(2, $fresh->score);
        $this->assertSame(20, $fresh->percentage);
    }

    /**
     * Requirement 4:
     * Multiple-choice / multi-option grading (exact-set grading).
     */
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

        // Freeze incorrect key [correct 1, wrong 1]
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

    /**
     * Requirement 5:
     * Regrade an already-published result.
     * Assert the student-facing result page shows the recalculated score.
     */
    public function test_regrade_already_published_result_immediately_shows_recalculated_score_on_student_page(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => false,
        ]);
        $question = $this->addSingleChoiceQuestion($exam, ['points' => 5]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $attempt = $this->startAttempt($student, $exam);

        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();

        // Student selects old correct
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $oldCorrect->id,
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // Teacher publishes grades
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(200);

        $this->assertTrue($attempt->fresh()->resultIsPublished());

        // Teacher regrades to newCorrect
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);

        // Student-facing attempt detail
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.percentage', 0)
            ->assertJsonPath('data.outcome', 'failed');

        // Student-facing exam attempts list
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}/attempts")
            ->assertStatus(200)
            ->assertJsonPath('data.0.score', 0)
            ->assertJsonPath('data.0.percentage', 0);
    }

    /**
     * Requirement 6:
     * Run the same regrade twice.
     * Assert idempotency and no duplicate grading records/history corruption.
     */
    public function test_run_the_same_regrade_twice_is_idempotent(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $oldCorrect->id,
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        Notification::fake();

        // First run
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1);

        Notification::assertSentToTimes($student, ResultAvailableNotification::class, 1);

        $answerCountBefore = ExamAnswer::query()->where('attempt_id', $attempt->id)->count();

        // Second run: same parameters
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 0);

        // No additional notification sent
        Notification::assertSentToTimes($student, ResultAvailableNotification::class, 1);

        $answerCountAfter = ExamAnswer::query()->where('attempt_id', $attempt->id)->count();
        $this->assertSame($answerCountBefore, $answerCountAfter, 'No duplicate answers created.');
        $this->assertSame(0, $attempt->fresh()->score);
    }

    /**
     * Requirement 7:
     * Attempt with essay/manual grading.
     * Ensure regrading an MCQ does not overwrite or corrupt essay/manual grades.
     */
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

    /**
     * Requirement 8:
     * Failed/pass boundary:
     * If the corrected MCQ changes the student's percentage across the pass threshold,
     * assert that ExamAttempt::outcome() changes accordingly.
     */
    public function test_regrade_updates_outcome_across_pass_fail_boundary(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => true,
            'pass_percentage' => 60,
        ]);
        $q1 = $this->addSingleChoiceQuestion($exam, ['points' => 1]);
        $q2 = $this->addSingleChoiceQuestion($exam, ['points' => 1]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);
        $attempt = $this->startAttempt($student, $exam);

        $q1OldCorrect = $this->correctOption($q1);
        $q1NewCorrect = $q1->options()->where('is_correct', false)->firstOrFail();
        $q2Correct = $this->correctOption($q2);

        // Student gets both right: 2/2 = 100% >= 60% => Passed
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", ['question_id' => $q1->id, 'option_id' => $q1OldCorrect->id])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", ['question_id' => $q2->id, 'option_id' => $q2Correct->id])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->assertSame(AttemptOutcome::Passed, $attempt->fresh()->outcome());

        // Teacher changes Q1 so student's answer becomes wrong: 1/2 = 50% < 60% => Failed
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$q1OldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$q1NewCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$q1->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);

        $this->assertSame(AttemptOutcome::Failed, $attempt->fresh()->outcome());
    }

    /**
     * Requirement 9:
     * Disqualified/flagged attempts:
     * Ensure regrading cannot accidentally turn a disqualified attempt into a passed attempt or remove its integrity status.
     */
    public function test_regrade_disqualified_attempt_never_turns_into_passed(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $newCorrect->id,
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // Flag and confirm integrity violation -> Disqualified
        $attempt->integrity_status = IntegrityStatus::Flagged;
        $attempt->save();
        ExamIntegrityReview::create([
            'attempt_id' => $attempt->id,
            'reviewer_id' => $teacher->id,
            'decision' => IntegrityReviewDecision::Flagged->value,
            'reviewed_at' => now(),
            'notes' => 'Confirmed cheating.',
        ]);

        $this->assertSame(AttemptOutcome::Disqualified, $attempt->fresh()->outcome());

        // Teacher regrades to newCorrect (student would now have 100%)
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);

        $fresh = $attempt->fresh();
        $this->assertSame(1, $fresh->score);
        $this->assertSame(IntegrityStatus::Flagged, $fresh->integrity_status);
        $this->assertSame(AttemptOutcome::Disqualified, $fresh->outcome(), 'Disqualified attempt must remain Disqualified.');
    }

    /**
     * Requirement 10:
     * Authorization:
     * Only authorized teacher/admin users can change the answer key and trigger regrading for attempts they are allowed to manage.
     */
    public function test_authorization_for_regrade_and_key_modification(): void
    {
        [$teacher, , , , $question] = $this->singleChoiceFixture();
        $correct = $this->correctOption($question);
        $outsider = $this->createUserWithRole(UserRole::Teacher);

        // Outsider cannot update option
        $this->actingAs($outsider, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$correct->id}", ['is_correct' => false])
            ->assertStatus(403);

        // Outsider cannot trigger regrade
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(403);

        // Unconfirmed regrade rejected with 422
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts")
            ->assertStatus(422);
    }

    /**
     * Requirement 11:
     * Student answer immutability:
     * Regrading must NEVER modify the student's submitted answer/options.
     */
    public function test_student_answer_remains_strictly_immutable_after_regrade(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();
        $question->update(['explanation_enabled' => true]);
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $oldCorrect->id,
                'explanation' => 'My detailed reason for choosing this.',
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $answerBefore = ExamAnswer::query()->where('attempt_id', $attempt->id)->where('question_id', $question->id)->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);

        $answerAfter = $answerBefore->fresh();
        $this->assertSame($answerBefore->option_id, $answerAfter->option_id);
        $this->assertSame('My detailed reason for choosing this.', $answerAfter->explanation);
        $this->assertSame($answerBefore->answered_at->toIso8601String(), $answerAfter->answered_at->toIso8601String());
    }

    /**
     * Requirement 12:
     * Student-facing review:
     * Teacher review and student review must show the same final grading state after regrade.
     */
    public function test_teacher_and_student_review_show_identical_grading_state_after_regrade(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $newCorrect = $question->options()->where('is_correct', false)->firstOrFail();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $oldCorrect->id,
            ])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$oldCorrect->id}", ['is_correct' => false])
            ->assertStatus(200);
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$newCorrect->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);

        $teacherRes = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->json('data');

        $studentRes = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($teacherRes['score'], $studentRes['score']);
        $this->assertSame($teacherRes['percentage'], $studentRes['percentage']);
        $this->assertSame($teacherRes['outcome'], $studentRes['outcome']);
        $this->assertSame($teacherRes['questions'][0]['is_correct'], $studentRes['questions'][0]['review']['is_correct']);
        $this->assertSame($teacherRes['questions'][0]['points_earned'], $studentRes['questions'][0]['review']['points_earned']);
    }

    /**
     * Single-choice option update automatically unsets other options.
     */
    public function test_updating_option_as_correct_in_single_choice_unsets_other_options(): void
    {
        [$teacher, , , , $question] = $this->singleChoiceFixture();
        $oldCorrect = $this->correctOption($question);
        $otherOption = $question->options()->where('is_correct', false)->firstOrFail();

        // Mark other option as correct
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$otherOption->id}", ['is_correct' => true])
            ->assertStatus(200);

        $this->assertTrue($otherOption->fresh()->is_correct);
        $this->assertFalse($oldCorrect->fresh()->is_correct, 'Old correct option should be automatically unset.');

        // Calling regrade directly succeeds without needing manual unchecking of old option
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", ['confirmed' => true])
            ->assertStatus(200);
    }

    /**
     * Regrade leaves in progress and expired attempts and deadlines untouched.
     */
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

    /**
     * User bug reproduction:
     * Question options originally [Option 1: Russia (correct), Option 2: Nigeria (wrong)].
     * Student selects Option 2 (Nigeria). Initial score = 0.
     * Teacher renames Option 1 to "ب- نيجيريا" and marks it correct.
     * Teacher renames Option 2 to "ب- روسيا".
     * Teacher regrades.
     * The system must resolve the correct answer by normalized text, award 1 point to the student,
     * and update both teacher attempt review and student attempt review to show Nigeria as correct.
     */
    public function test_regrade_resolves_options_by_normalized_text_when_teacher_swaps_or_edits_option_wording(): void
    {
        [$teacher, $student, , $exam, $question] = $this->singleChoiceFixture();

        // Configure options to exact user names
        $options = $question->options()->orderBy('position')->get();
        $opt1 = $options[0];
        $opt2 = $options[1];
        $opt1->update(['option_text' => 'أ- روسيا', 'is_correct' => true]);
        $opt2->update(['option_text' => 'ب- نيجيريا', 'is_correct' => false]);

        $attempt = $this->startAttempt($student, $exam);

        // Student selects Option 2 (Nigeria)
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $opt2->id,
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        // Before regrade: student scored 0 points
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);

        // Teacher edits Option 1 to "ب- نيجيريا" and marks it correct
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$opt1->id}", [
                'option_text' => 'ب- نيجيريا',
                'is_correct' => true,
            ])
            ->assertStatus(200);

        // Teacher edits Option 2 to "ب- روسيا"
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/options/{$opt2->id}", [
                'option_text' => 'ب- روسيا',
                'is_correct' => false,
            ])
            ->assertStatus(200);

        // Teacher triggers regrade passing the updated correct option ids
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/regrade-submitted-attempts", [
                'confirmed' => true,
                'correct_option_ids' => [$opt1->id],
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_regraded', 1)
            ->assertJsonPath('data.scores_changed', 1);

        // After regrade: student score updated to 1
        $studentReview = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.questions.0.review.is_correct', true)
            ->assertJsonPath('data.questions.0.review.points_earned', 1);

        // Teacher attempt review modal endpoint reflects the same updated grade and option breakdown
        $teacherReview = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 1)
            ->assertJsonPath('data.percentage', 100)
            ->assertJsonPath('data.questions.0.is_correct', true)
            ->assertJsonPath('data.questions.0.points_earned', 1);

        // Check snapshot option breakdown in teacher review: Nigeria is marked correct
        $nigeriaOpt = collect($teacherReview->json('data.questions.0.options'))->firstWhere('option_text', 'ب- نيجيريا');
        $this->assertNotNull($nigeriaOpt);
        $this->assertTrue($nigeriaOpt['is_correct']);
        $this->assertTrue($nigeriaOpt['is_selected']);
    }
}
