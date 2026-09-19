<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\Question;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class QuestionImageTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_teacher_can_upload_replace_and_remove_a_question_image(): void
    {
        Storage::fake('public');
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $exam = $this->makeExam($teacher, $this->createCourse($teacher));
        $question = Question::factory()->create(['exam_id' => $exam->id]);

        $first = UploadedFile::fake()->image('map.png', 1200, 800);
        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/questions/{$question->id}/image", ['image' => $first])
            ->assertOk()
            ->assertJsonPath('data.image_url', fn ($url) => str_starts_with($url, '/storage/exam-question-images/'));

        $firstPath = $question->fresh()->image_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/questions/{$question->id}/image")
            ->assertOk()
            ->assertJsonPath('data.image_url', null);

        Storage::disk('public')->assertMissing($firstPath);
        $this->assertNull($question->fresh()->image_path);
    }

    public function test_uploaded_question_image_is_frozen_for_the_attempt(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);
        $question = $this->addSingleChoiceQuestion($exam, ['image_path' => 'exam-question-images/map.png']);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertCreated();

        $attempt = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertCreated();

        $snapshot = collect($attempt->json('data.questions'))->firstWhere('id', $question->id);
        $this->assertStringEndsWith('/storage/exam-question-images/map.png', $snapshot['image_url']);
    }
}
