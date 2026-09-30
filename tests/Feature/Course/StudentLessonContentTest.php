<?php

namespace Tests\Feature\Course;

use App\Enums\UserRole;
use App\Models\Lesson;
use Tests\Feature\ApiTestCase;

/**
 * Lesson content must actually reach students (P0.3).
 *
 * Regression targets:
 *  - LessonResource omitted `content`, so teachers authored body text students
 *    could never read;
 *  - GET .../lessons/{id}/progress did not load the lesson relation, so the
 *    student lesson page fell back to a generic "Lesson" heading.
 */
class StudentLessonContentTest extends ApiTestCase
{
    private function publishedLessonWithContent(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->create([
            'unit_id' => $unit->id,
            'content' => "Photosynthesis turns light into energy.\nIt happens in the chloroplast.",
            'is_published' => true,
        ]);

        return [$teacher, $course, $lesson];
    }

    private function enroll(array $pair): \App\Models\User
    {
        [$teacher, $course] = $pair;
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return $student;
    }

    public function test_enrolled_student_can_read_lesson_content(): void
    {
        [, , $lesson] = $pair = $this->publishedLessonWithContent();
        $student = $this->enroll($pair);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSame($lesson->content, $response->json('data.content'));
        $this->assertSame($lesson->title, $response->json('data.title'));
    }

    public function test_progress_endpoint_includes_the_lesson_title_and_content(): void
    {
        [, , $lesson] = $pair = $this->publishedLessonWithContent();
        $student = $this->enroll($pair);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}/progress")
            ->assertStatus(200);

        $this->assertSame($lesson->title, $response->json('data.lesson.title'));
        $this->assertSame($lesson->content, $response->json('data.lesson.content'));
    }

    public function test_non_enrolled_student_cannot_read_lesson_content(): void
    {
        [, , $lesson] = $this->publishedLessonWithContent();
        $outsider = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(403);
    }

    public function test_unpublished_lesson_is_not_readable_by_students(): void
    {
        [, , $lesson] = $pair = $this->publishedLessonWithContent();
        $student = $this->enroll($pair);

        $lesson->update(['is_published' => false]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(403);
    }

    public function test_teacher_cannot_use_the_student_lesson_endpoint(): void
    {
        [$teacher, , $lesson] = $this->publishedLessonWithContent();

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(403);
    }

    public function test_guest_cannot_read_lesson_content(): void
    {
        [, , $lesson] = $this->publishedLessonWithContent();

        $this->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertStatus(401);
    }
}
