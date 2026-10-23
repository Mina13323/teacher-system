<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamIntegritySetting;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * A homework is an exam with type=homework: a required open/close window, no
 * proctoring, and an attempt that stays open until the window closes.
 */
class HomeworkTest extends ApiTestCase
{
    use InteractsWithExams;

    private User $teacher;
    private Course $course;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
        $this->student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$this->course->id}/enroll")->assertStatus(201);
    }

    private function homework(array $attributes = []): Exam
    {
        $exam = $this->makePublishedExam($this->teacher, $this->course, array_merge([
            'type' => Exam::TYPE_HOMEWORK,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(3),
            'max_attempts' => 1,
        ], $attributes));
        $this->addSingleChoiceQuestion($exam);

        return $exam->fresh();
    }

    public function test_a_teacher_creates_a_homework_with_open_and_close_times(): void
    {
        $starts = now()->addDay()->startOfHour();
        $ends = $starts->copy()->addDays(2);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$this->course->id}/exams", [
                'title' => 'Homework 1',
                'type' => 'homework',
                'starts_at' => $starts->toIso8601String(),
                'ends_at' => $ends->toIso8601String(),
            ])->assertStatus(201)
            ->assertJsonPath('data.type', 'homework');

        // The duration spans the window, so attempts last until it closes.
        $this->assertSame(2 * 24 * 60, Exam::find($response->json('data.id'))->duration_minutes);
    }

    public function test_a_homework_needs_both_times(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$this->course->id}/exams", ['title' => 'Homework', 'type' => 'homework'])
            ->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
    }

    public function test_exams_stay_exams_by_default(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$this->course->id}/exams", ['title' => 'Quiz', 'duration_minutes' => 20])
            ->assertStatus(201)->assertJsonPath('data.type', 'exam')->assertJsonPath('data.duration_minutes', 20);
    }

    public function test_a_homework_is_never_proctored_and_stays_open_until_it_closes(): void
    {
        $homework = $this->homework();
        // Even an integrity row with everything on is ignored for a homework.
        ExamIntegritySetting::create(['exam_id' => $homework->id, 'fullscreen_required' => true, 'detect_tab_switch' => true, 'detect_window_blur' => true, 'prevent_copy' => true, 'terminate_on_violation' => true]);

        $attempt = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$homework->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.exam_type', 'homework')
            ->json('data');

        $this->assertSame($homework->ends_at->toISOString(), $attempt['expires_at']);
        $this->assertArrayHasKey('integrity_rules', $attempt);
        foreach (['fullscreen_required', 'detect_tab_switch', 'detect_window_blur', 'prevent_copy', 'prevent_paste', 'terminate_on_violation'] as $rule) {
            $this->assertFalse((bool) $attempt['integrity_rules'][$rule], $rule);
        }

        // Leaving and coming back later resumes the same attempt.
        $this->travel(1)->days();
        $again = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$homework->id}/start", ['rules_acknowledged' => true, 'compact_response' => true])
            ->assertStatus(201)->json('data');
        $this->assertSame($attempt['id'], $again['id']);
        $this->assertTrue($again['already_open']);
    }

    public function test_an_unsubmitted_homework_is_submitted_with_its_saved_answers_when_it_closes(): void
    {
        $homework = $this->homework(['ends_at' => now()->addHours(2)]);
        $attempt = $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$homework->id}/start", ['rules_acknowledged' => true])->json('data');
        $question = $homework->questions()->first();
        $correct = $question->options()->where('is_correct', true)->value('id');
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $question->id, 'option_ids' => [$correct]])
            ->assertOk();

        $this->travel(3)->hours();
        $this->artisan('attempts:process-expired')->assertExitCode(0);

        $stored = ExamAttempt::find($attempt['id']);
        $this->assertNotSame(ExamAttemptStatus::InProgress, $stored->status);
        $this->assertNotNull($stored->scored_at);
    }

    public function test_students_list_exams_and_homework_separately(): void
    {
        $homework = $this->homework();
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['title' => 'Real exam']);

        $ids = fn (string $query) => collect($this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/student/exams'.$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$homework->id], $ids('?type=homework'));
        $this->assertSame([$exam->id], $ids('?type=exam'));
        $this->assertEqualsCanonicalizing([$homework->id, $exam->id], $ids(''));

        // The homework list shows each one's open and close times.
        $this->actingAs($this->student, 'sanctum')->getJson('/api/v1/student/exams?type=homework')
            ->assertJsonPath('data.0.type', 'homework')
            ->assertJsonPath('data.0.ends_at', $homework->ends_at->toIso8601String());
    }

    public function test_teachers_list_exams_and_homework_separately(): void
    {
        $homework = $this->homework();
        $exam = $this->makePublishedExam($this->teacher, $this->course, ['title' => 'Real exam']);

        $ids = fn (string $query) => collect($this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$this->course->id}/exams".$query)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$homework->id], $ids('?type=homework'));
        $this->assertSame([$exam->id], $ids('?type=exam'));
        $this->assertCount(2, $ids(''));
    }

    public function test_the_type_cannot_change_once_students_have_started(): void
    {
        $homework = $this->homework();
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$homework->id}/start", ['rules_acknowledged' => true])->assertStatus(201);

        $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$homework->id}", ['type' => 'exam'])
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_moving_the_close_time_keeps_the_duration_spanning_the_window(): void
    {
        $homework = $this->homework();
        $newEnd = $homework->ends_at->copy()->addDay();

        $this->actingAs($this->teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$homework->id}", ['ends_at' => $newEnd->toIso8601String()])
            ->assertOk();

        $fresh = $homework->fresh();
        $this->assertSame((int) ceil($fresh->starts_at->diffInSeconds($fresh->ends_at, true) / 60), $fresh->duration_minutes);
    }
}
