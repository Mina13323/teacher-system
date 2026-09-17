<?php

namespace Tests\Feature\Exam;

use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamTemplate;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\ExamTemplateSeeder;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Exam templates: the seeded catalog, applying a template to an exam, saving an
 * exam back out as a template, and the bulk authoring endpoint the whole flow
 * feeds into.
 */
class ExamTemplateTest extends ApiTestCase
{
    use InteractsWithExams;

    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function makeStudent(): User
    {
        return $this->createUserWithRole(UserRole::Student);
    }

    private function draftExam(User $teacher): Exam
    {
        return $this->makeExam($teacher, $this->createCourse($teacher, ['status' => 'published']));
    }

    private function seededTemplate(string $presetKey): ExamTemplate
    {
        $this->seed(ExamTemplateSeeder::class);

        return ExamTemplate::where('preset_key', $presetKey)->firstOrFail();
    }

    // ---- Seeded catalog ----------------------------------------------------

    public function test_seeded_catalog_contains_the_standard_thirty_twenty_template(): void
    {
        $template = $this->seededTemplate('standard_30_20')->load('sections');

        $this->assertTrue($template->is_system, 'A seeded template must be read-only.');
        $this->assertNull($template->created_by, 'A seeded template belongs to nobody.');
        $this->assertSame(50, $template->totalQuestions());
        // 30 MCQ x 1 mark + 20 essay x 2 marks.
        $this->assertSame(70, $template->totalPoints());

        $mcq = $template->sections->firstWhere('question_type', QuestionType::SingleChoice);
        $this->assertSame(30, $mcq->quantity);
        $this->assertSame(1, $mcq->points);

        $essay = $template->sections->firstWhere('question_type', QuestionType::Essay);
        $this->assertSame(20, $essay->quantity);
        $this->assertSame(2, $essay->points);
    }

    public function test_seeding_twice_does_not_duplicate_the_catalog(): void
    {
        $this->seed(ExamTemplateSeeder::class);
        $firstCount = ExamTemplate::count();

        $this->seed(ExamTemplateSeeder::class);

        $this->assertSame($firstCount, ExamTemplate::count());
    }

    public function test_a_teacher_sees_system_templates_and_their_own_but_not_another_teachers(): void
    {
        $this->seed(ExamTemplateSeeder::class);

        $teacher = $this->makeTeacher();
        $stranger = $this->makeTeacher();

        ExamTemplate::factory()->create(['created_by' => $teacher->id, 'name' => 'Mine']);
        ExamTemplate::factory()->create(['created_by' => $stranger->id, 'name' => 'Theirs']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/exam-templates')
            ->assertStatus(200);

        // The list is paginated, so the rows sit under data.data.
        $response->assertJsonPath('data.meta.per_page', 100);

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains('Mine'));
        $this->assertFalse($names->contains('Theirs'));
        $this->assertTrue($names->contains('Standard Exam — 30 MCQ + 20 Essay'));
    }

    // ---- Applying ----------------------------------------------------------

    public function test_applying_a_template_appends_blank_questions_with_the_template_marks(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);
        $template = $this->seededTemplate('standard_30_20');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/apply-template", ['template_id' => $template->id])
            ->assertStatus(201)
            ->assertJsonPath('data.created_count', 50);

        $questions = $exam->questions()->with('options')->get();

        $this->assertCount(50, $questions);

        $mcq = $questions->where('type', QuestionType::SingleChoice);
        $essay = $questions->where('type', QuestionType::Essay);

        $this->assertCount(30, $mcq);
        $this->assertCount(20, $essay);

        $this->assertTrue($mcq->every(fn (Question $q) => $q->points === 1));
        $this->assertTrue($essay->every(fn (Question $q) => $q->points === 2));

        // Nothing is pre-filled: the teacher writes every question.
        $this->assertTrue($questions->every(fn (Question $q) => $q->question_text === ''));
        $this->assertTrue($questions->every(fn (Question $q) => $q->reference_answer === null));

        // MCQ rows are scaffolded with blank options and no answer marked yet.
        $this->assertTrue($mcq->every(fn (Question $q) => $q->options->count() === 4));
        $this->assertTrue($mcq->every(fn (Question $q) => $q->options->every(fn ($o) => $o->is_correct === false)));

        // An essay question carries no options at all.
        $this->assertTrue($essay->every(fn (Question $q) => $q->options->isEmpty()));
    }

    public function test_applying_a_template_appends_after_existing_questions(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);

        $this->addSingleChoiceQuestion($exam);
        $this->addSingleChoiceQuestion($exam);
        $template = $this->seededTemplate('quick_quiz_10');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/apply-template", ['template_id' => $template->id])
            ->assertStatus(201)
            ->assertJsonPath('data.total_questions', 12);

        $positions = $exam->questions()->pluck('position')->all();

        $this->assertCount(12, $positions);
        $this->assertSame(range(1, 12), $positions, 'Positions must be a single unbroken sequence.');
    }

    public function test_applying_a_template_to_a_published_exam_is_rejected(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $template = $this->seededTemplate('quick_quiz_10');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/apply-template", ['template_id' => $template->id])
            ->assertStatus(422);

        $this->assertSame(1, $exam->questions()->count(), 'No questions may be added to a published exam.');
    }

    public function test_a_scaffolded_exam_cannot_be_published_until_it_is_filled_in(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);
        $exam->update(['duration_minutes' => 30, 'pass_percentage' => 50, 'max_attempts' => 1]);
        $template = $this->seededTemplate('quick_quiz_10');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/apply-template", ['template_id' => $template->id])
            ->assertStatus(201);

        // Blank questions and blank options must not reach students.
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(422);

        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    // ---- Saving an exam back out -------------------------------------------

    public function test_saving_an_exam_as_a_template_captures_structure_only(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);

        // 3 multiple-choice at 2 marks and 2 essays at 4 marks.
        for ($i = 0; $i < 3; $i++) {
            $this->addSingleChoiceQuestion($exam, ['points' => 2]);
        }
        for ($i = 0; $i < 2; $i++) {
            Question::factory()->create([
                'exam_id' => $exam->id,
                'type' => QuestionType::Essay->value,
                'points' => 4,
                'position' => $exam->questions()->count() + 1,
                'reference_answer' => 'PRIVATE-MODEL-ANSWER',
            ]);
        }

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/save-as-template", ['name' => 'My paper'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'My paper')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.created_by', $teacher->id)
            ->assertJsonPath('data.total_questions', 5)
            ->assertJsonPath('data.total_points', 14);

        $template = ExamTemplate::where('name', 'My paper')->firstOrFail();

        // Collapsed into one row per type/mark combination.
        $sections = $template->sections()->get();
        $this->assertCount(2, $sections);
        $this->assertSame(3, $sections->firstWhere('question_type', QuestionType::SingleChoice)->quantity);
        $this->assertSame(2, $sections->firstWhere('question_type', QuestionType::Essay)->quantity);

        // Structure only — no wording and no answer key is copied across.
        $this->assertStringNotContainsString(
            'PRIVATE-MODEL-ANSWER',
            json_encode($template->toArray()) . json_encode($sections->toArray())
        );
    }

    public function test_saving_an_exam_with_no_questions_as_a_template_is_rejected(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/save-as-template", ['name' => 'Empty'])
            ->assertStatus(422);

        $this->assertSame(0, ExamTemplate::where('name', 'Empty')->count());
    }

    // ---- Template ownership --------------------------------------------------

    public function test_a_teacher_cannot_delete_a_system_template(): void
    {
        $teacher = $this->makeTeacher();
        $template = $this->seededTemplate('quick_quiz_10');

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exam-templates/{$template->id}")
            ->assertStatus(403);

        $this->assertNotNull(ExamTemplate::find($template->id));
    }

    public function test_a_teacher_can_delete_their_own_template(): void
    {
        $teacher = $this->makeTeacher();
        $template = ExamTemplate::factory()->create(['created_by' => $teacher->id]);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exam-templates/{$template->id}")
            ->assertStatus(200);

        $this->assertNull(ExamTemplate::find($template->id));
    }

    // ---- Bulk authoring ------------------------------------------------------

    public function test_bulk_sync_creates_and_updates_questions_in_one_request(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);
        $existing = $this->addSingleChoiceQuestion($exam);
        $existingOption = $this->correctOption($existing);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/questions", [
                'questions' => [
                    [
                        'question_text' => 'Brand new question',
                        'type' => 'single_choice',
                        'points' => 1,
                        'options' => [
                            ['option_text' => 'Right', 'is_correct' => true],
                            ['option_text' => 'Wrong', 'is_correct' => false],
                        ],
                    ],
                    [
                        'id' => $existing->id,
                        'question_text' => 'Edited question',
                        'type' => 'single_choice',
                        'points' => 3,
                        'options' => [
                            ['id' => $existingOption->id, 'option_text' => 'Still correct', 'is_correct' => true],
                            ['option_text' => 'Distractor', 'is_correct' => false],
                        ],
                    ],
                ],
            ])
            ->assertStatus(200);

        $questions = $exam->questions()->with('options')->orderBy('position')->get();

        $this->assertCount(2, $questions, 'The existing row must be updated, not duplicated.');
        $this->assertSame('Brand new question', $questions[0]->question_text);
        $this->assertSame(1, $questions[0]->position);
        $this->assertSame('Edited question', $questions[1]->question_text);
        $this->assertSame(3, $questions[1]->points);
        $this->assertSame(2, $questions[1]->position);
        $this->assertSame('Still correct', $existingOption->fresh()->option_text);
    }

    public function test_bulk_sync_rejects_a_question_belonging_to_another_exam(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);
        $foreignExam = $this->draftExam($teacher);
        $foreign = $this->addSingleChoiceQuestion($foreignExam);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/questions", [
                'questions' => [
                    ['id' => $foreign->id, 'question_text' => 'Hijacked', 'type' => 'single_choice', 'points' => 1],
                ],
            ])
            ->assertStatus(422);

        $this->assertNotSame('Hijacked', $foreign->fresh()->question_text);
    }

    public function test_bulk_sync_drops_options_when_a_question_becomes_an_essay(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->draftExam($teacher);
        $question = $this->addSingleChoiceQuestion($exam);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/questions", [
                'questions' => [
                    [
                        'id' => $question->id,
                        'question_text' => 'Now an essay',
                        'type' => 'essay',
                        'points' => 4,
                        'reference_answer' => 'Grading aid',
                    ],
                ],
            ])
            ->assertStatus(200);

        $fresh = $question->fresh(['options']);

        $this->assertSame(QuestionType::Essay, $fresh->type);
        $this->assertSame('Grading aid', $fresh->reference_answer);
        $this->assertTrue($fresh->options->isEmpty(), 'No stale answer key may survive the type change.');
    }

    public function test_bulk_sync_refuses_to_edit_a_published_exam(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $question = $exam->questions()->first();

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/questions", [
                'questions' => [
                    ['id' => $question->id, 'question_text' => 'Changed after publishing', 'type' => 'single_choice', 'points' => 1],
                ],
            ])
            ->assertStatus(422);

        $this->assertNotSame('Changed after publishing', $question->fresh()->question_text);
    }

    // ---- Model answer privacy ------------------------------------------------

    /**
     * The teacher's reference answer is a private grading aid. It must never
     * appear in anything a student can read.
     */
    public function test_reference_answer_is_never_shown_to_the_student(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => QuestionType::Essay->value,
            'points' => 2,
            'position' => 2,
            'reference_answer' => 'SECRET-MODEL-ANSWER',
        ]);

        $student = $this->makeStudent();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->firstOrFail();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}")
            ->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('SECRET-MODEL-ANSWER', $content);
        $this->assertStringNotContainsString('reference_answer', $content);
    }
}
