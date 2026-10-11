<?php

namespace Tests\Feature\Exam\Concerns;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Essay-grading fixtures built through the real start / answer / submit
 * endpoints, so the frozen snapshot is the one students actually get.
 * Requires InteractsWithExams and an ApiTestCase.
 */
trait BuildsEssayAttempts
{
    private User $teacher;

    private Course $course;

    private User $student;

    protected function setUpEssayFixture(): void
    {
        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
        $this->student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$this->course->id}/enroll")
            ->assertStatus(201);
        Notification::fake();
    }

    /** A published exam with one single-choice question and `$essays` essay questions (10 points each). */
    private function exam(int $essays = 1, array $attributes = []): Exam
    {
        $exam = $this->makeExam($this->teacher, $this->course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'max_attempts' => 5,
            'show_result_immediately' => false,
        ], $attributes));

        $this->addSingleChoiceQuestion($exam, ['points' => 2]);
        for ($i = 0; $i < $essays; $i++) {
            Question::factory()->create([
                'exam_id' => $exam->id,
                'type' => 'essay',
                'points' => 10,
                'position' => $i + 2,
            ]);
        }

        return $exam;
    }

    private function start(Exam $exam): array
    {
        return $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->json('data');
    }

    /** Essay question ids in a stable order (attempts may shuffle questions). */
    private function essayIds(array $attempt): array
    {
        $ids = array_values(array_map(
            fn ($q) => $q['id'],
            array_filter($attempt['questions'], fn ($q) => $q['question_type'] === 'essay')
        ));
        sort($ids);

        return $ids;
    }

    private function choiceId(array $attempt): int
    {
        return (int) collect($attempt['questions'])->firstWhere('question_type', 'single_choice')['id'];
    }

    private function writeEssay(array $attempt, int $questionId, string $text): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $questionId, 'answer_text' => $text])
            ->assertOk();
    }

    private function submit(array $attempt): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/submit")
            ->assertOk();
    }

    private function grade(array $attempt, int $questionId, mixed $points, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt['id']}/grade-essay", [
                'question_id' => $questionId,
                'awarded_points' => $points,
                'feedback' => 'Reviewed.',
            ]);
    }

    private function publish(array $attempt, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt['id']}/publish-grades");
    }

    /** Everything a rejected publication must leave untouched. */
    private function snapshotOf(int $attemptId): array
    {
        $row = ExamAttempt::findOrFail($attemptId);

        return [
            'attempt' => collect($row->getAttributes())->except(['updated_at'])->all(),
            'answers' => ExamAnswer::where('attempt_id', $attemptId)->orderBy('id')->get()
                ->map(fn ($a) => collect($a->getAttributes())->except(['updated_at'])->all())->all(),
        ];
    }

}
