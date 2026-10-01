<?php

namespace Tests\Feature\Exam;

use App\Actions\Exam\CalculateExamResultAction;
use App\Actions\Exam\GradeExamAttemptAction;
use App\Actions\Exam\StartExamAttemptAction;
use App\Enums\ExamAttemptStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptOption;
use App\Models\ExamAttemptQuestion;
use App\Models\Option;
use App\Models\Question;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Multiple-choice (multi-select) grading end-to-end.
 *
 * Grading semantics under test — exact-set match (all-or-nothing):
 *   student selects exactly the correct set   -> full points
 *   any other selection (partial, extra, none) -> zero points
 */
class MultipleChoiceGradingTest extends ApiTestCase
{
    use InteractsWithExams;

    private function enrolledStudentWithMultiExam(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'show_result_immediately' => true,
        ]);

        $multi = $this->addMultipleChoiceQuestion($exam);
        // A plain single-choice question must keep working alongside multi.
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return [$student, $exam, $multi];
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

    public function test_multi_select_can_select_all_correct_options_and_scores_full(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $correctIds = collect($multi['correct'])->map->id->all();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => $correctIds,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('exam_answers', [
            'attempt_id' => $attempt->id,
            'question_id' => $multi['question']->id,
        ]);
        $this->assertSame(2, ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $multi['question']->id)
            ->firstOrFail()
            ->selectedOptions()
            ->count());

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit");

        $response->assertStatus(200);
        // 2 (multi) + 1 (single) = 3 total points; multi correct but single unanswered.
        $this->assertSame(2, $response->json('data.score'));
    }

    public function test_multi_select_partial_selection_scores_zero(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => [$multi['correct'][0]->id],
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);
    }

    public function test_multi_select_wrong_plus_correct_scores_zero(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => [$multi['correct'][0]->id, $multi['correct'][1]->id, $multi['wrong'][0]->id],
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);
    }

    public function test_multi_select_only_wrong_options_scores_zero(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => [$multi['wrong'][0]->id, $multi['wrong'][1]->id],
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 0);
    }

    public function test_unanswered_multi_question_scores_zero_on_submit(): void
    {
        [$student, $exam] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit");

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.score'));
    }

    public function test_single_choice_still_works_and_rejects_multiple_selections(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        // Find the single-choice question and its options from the snapshot.
        $singleQuestion = $attempt->attemptQuestions()
            ->where('question_type', QuestionType::SingleChoice->value)
            ->firstOrFail();

        $snapshotOptions = ExamAttemptOption::query()
            ->where('attempt_question_id', $singleQuestion->id)
            ->get();

        $correctId = (int) $snapshotOptions->firstWhere('is_correct', true)->option_id;
        $wrongId = (int) $snapshotOptions->firstWhere('is_correct', false)->option_id;

        // Two selections on a single-choice question are rejected.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $singleQuestion->question_id,
                'option_ids' => [$correctId, $wrongId],
            ])
            ->assertStatus(422);

        // The legacy single `option_id` field keeps working.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $singleQuestion->question_id,
                'option_id' => $correctId,
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 1);
    }

    public function test_selection_set_replaces_previous_selection(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $ids = fn (array $options) => collect($options)->map->id->all();

        // Select all four, then correct down to just the two correct options.
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => array_merge($ids($multi['correct']), $ids($multi['wrong'])),
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => $ids($multi['correct']),
            ])
            ->assertStatus(200);

        $answer = ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $multi['question']->id)
            ->firstOrFail();

        // Exactly two children remain — no duplicates, no leftovers.
        $this->assertSame(2, $answer->selectedOptions()->count());

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200)
            ->assertJsonPath('data.score', 2);
    }

    public function test_explicit_empty_selection_clears_the_answer(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => collect($multi['correct'])->map->id->all(),
            ])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => [],
            ])
            ->assertStatus(200);

        $this->assertDatabaseMissing('exam_answers', [
            'attempt_id' => $attempt->id,
            'question_id' => $multi['question']->id,
        ]);
    }

    public function test_legacy_single_option_id_answers_grade_exactly_as_before(): void
    {
        // Simulate a pre-multi-select answer row: only exam_answers.option_id,
        // no exam_answer_options children. Grading must fall back to that column.
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $singleQuestion = $attempt->attemptQuestions()
            ->where('question_type', QuestionType::SingleChoice->value)
            ->firstOrFail();

        $correctId = (int) ExamAttemptOption::query()
            ->where('attempt_question_id', $singleQuestion->id)
            ->where('is_correct', true)
            ->firstOrFail()
            ->option_id;

        ExamAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $singleQuestion->question_id,
            'option_id' => $correctId,
            'answered_at' => now(),
        ]);

        $result = app(GradeExamAttemptAction::class)->execute($attempt->fresh());

        $this->assertSame(1, $result->score);
        $this->assertTrue((bool) ExamAnswer::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $singleQuestion->question_id)
            ->firstOrFail()
            ->is_correct);
    }

    public function test_snapshot_preserves_multiple_correct_options(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $snapshotQuestion = ExamAttemptQuestion::query()
            ->where('attempt_id', $attempt->id)
            ->where('question_id', $multi['question']->id)
            ->firstOrFail();

        $this->assertSame(
            2,
            ExamAttemptOption::query()
                ->where('attempt_question_id', $snapshotQuestion->id)
                ->where('is_correct', true)
                ->count()
        );
    }

    public function test_grading_is_deterministic(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => collect($multi['correct'])->map->id->all(),
            ])
            ->assertStatus(200);

        $first = app(GradeExamAttemptAction::class)->execute($attempt->fresh());
        $second = app(GradeExamAttemptAction::class)->execute($attempt->fresh());

        $this->assertSame($first->score, $second->score);
        $this->assertSame($first->percentage, $second->percentage);

        $calc = app(CalculateExamResultAction::class);
        $a = $calc->execute($attempt->fresh());
        $b = $calc->execute($attempt->fresh());

        $this->assertSame($a['earned_points'], $b['earned_points']);
        $this->assertSame($a['raw_percentage'], $b['raw_percentage']);
    }

    public function test_publish_validation_requires_exactly_one_correct_for_single_choice(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        $question = $this->addSingleChoiceQuestion($exam);
        // A second "correct" option makes the key invalid for single_choice.
        Option::factory()->create([
            'question_id' => $question->id,
            'option_text' => 'Second correct',
            'is_correct' => true,
            'position' => 3,
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(422);
    }

    public function test_publish_validation_requires_at_least_two_correct_for_multiple_choice(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course);

        // Only one correct option on a multiple_choice question: not publishable.
        $question = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => 'multiple_choice',
            'points' => 2,
            'position' => 1,
        ]);
        Option::factory()->create(['question_id' => $question->id, 'is_correct' => true, 'position' => 1]);
        Option::factory()->create(['question_id' => $question->id, 'is_correct' => false, 'position' => 2]);
        Option::factory()->create(['question_id' => $question->id, 'is_correct' => false, 'position' => 3]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(422);

        // Add a second correct option: now publishable.
        Option::factory()->create(['question_id' => $question->id, 'is_correct' => true, 'position' => 4]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(200);
    }

    public function test_multi_answer_response_exposes_the_selection_set(): void
    {
        [$student, $exam, $multi] = $this->enrolledStudentWithMultiExam();
        $attempt = $this->startAttempt($student, $exam);

        $ids = collect($multi['correct'])->map->id->sort()->values()->all();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $multi['question']->id,
                'option_ids' => $ids,
            ])
            ->assertStatus(200);

        $question = collect($response->json('data.questions'))
            ->firstWhere('id', $multi['question']->id);

        $this->assertSame($ids, collect($question['selected_option_ids'])->map(fn ($v) => (int) $v)->sort()->values()->all());
        $this->assertCount(2, collect($question['options'])->where('selected', true));
    }
}
