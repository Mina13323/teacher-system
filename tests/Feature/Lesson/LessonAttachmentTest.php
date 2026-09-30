<?php

namespace Tests\Feature\Lesson;

use App\Enums\UserRole;
use App\Models\Lesson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ApiTestCase;

/**
 * P2 — Lesson attachments: private file storage, authorized downloads
 * (enrolled students + course staff only), teacher management, ordering.
 */
class LessonAttachmentTest extends ApiTestCase
{
    private function lessonWithStudent(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->create([
            'unit_id' => $unit->id,
            'is_published' => true,
            'content' => 'Lesson body.',
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        return [$teacher, $course, $lesson, $student];
    }

    public function test_teacher_uploads_attachment_and_student_sees_metadata(): void
    {
        Storage::fake('local');
        [$teacher, $course, $lesson, $student] = $this->lessonWithStudent();

        $res = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/attachments", [
                'title' => 'Worksheet PDF',
                'file' => UploadedFile::fake()->create('worksheet.pdf', 64, 'application/pdf'),
            ])->assertStatus(201);

        $attachmentId = $res->json('data.id');
        Storage::disk('local')->assertExists($res->json('data.file_path'));
        $this->assertStringStartsWith('lesson-attachments/', $res->json('data.file_path'));

        // The student lesson payload includes the attachment metadata.
        $show = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(200);

        $attachments = collect($show->json('data.attachments'));
        $this->assertCount(1, $attachments);
        $this->assertSame('Worksheet PDF', $attachments->first()['title']);
        $this->assertSame('worksheet.pdf', $attachments->first()['file_name']);
    }

    public function test_download_is_authorized_per_user(): void
    {
        Storage::fake('local');
        [$teacher, $course, $lesson, $student] = $this->lessonWithStudent();

        $res = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/attachments", [
                'title' => 'Notes',
                'file' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain'),
            ])->assertStatus(201);
        $attachmentId = $res->json('data.id');

        // Enrolled student downloads fine.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/lesson-attachments/{$attachmentId}/file")
            ->assertStatus(200);

        // Teacher (course staff) downloads fine.
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/lesson-attachments/{$attachmentId}/file")
            ->assertStatus(200);

        // Non-enrolled student and foreign teacher are locked out.
        $stranger = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/lesson-attachments/{$attachmentId}/file")
            ->assertStatus(403);

        $teacherB = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/lesson-attachments/{$attachmentId}/file")
            ->assertStatus(403);
    }

    public function test_delete_removes_file_and_record_and_is_authorized(): void
    {
        Storage::fake('local');
        [$teacher, $course, $lesson, $student] = $this->lessonWithStudent();

        $res = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/attachments", [
                'title' => 'Temp',
                'file' => UploadedFile::fake()->create('temp.pdf', 8, 'application/pdf'),
            ])->assertStatus(201);

        $attachmentId = $res->json('data.id');
        $path = $res->json('data.file_path');

        // Student cannot delete.
        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/v1/teacher/lesson-attachments/{$attachmentId}")
            ->assertStatus(403);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/lesson-attachments/{$attachmentId}")
            ->assertStatus(200);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('lesson_attachments', ['id' => $attachmentId]);
    }

    public function test_attachments_keep_teacher_ordering(): void
    {
        Storage::fake('local');
        [$teacher, , $lesson, $student] = $this->lessonWithStudent();

        foreach (['First', 'Second', 'Third'] as $i => $title) {
            $this->actingAs($teacher, 'sanctum')
                ->postJson("/api/v1/teacher/lessons/{$lesson->id}/attachments", [
                    'title' => $title,
                    'file' => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'),
                ])->assertStatus(201);
        }

        // Teacher moves the last one to the front.
        $ids = $lesson->attachments()->pluck('id');
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/lesson-attachments/{$ids[2]}", ['position' => 0])
            ->assertStatus(200);

        $show = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(200);

        $titles = collect($show->json('data.attachments'))->pluck('title')->all();
        $this->assertSame('Third', $titles[0]);
    }
}
