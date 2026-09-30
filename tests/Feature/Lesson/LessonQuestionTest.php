<?php

namespace Tests\Feature\Lesson;

use App\Enums\UserRole;
use App\Models\LessonQuestion;
use Tests\Feature\ApiTestCase;

/**
 * PHASE 4 §31 — Lesson Q&A: thread permissions, moderation, no cross-course leaks.
 */
class LessonQuestionTest extends ApiTestCase
{
    private function lessonScenario(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = \App\Models\Lesson::factory()->create(['unit_id' => $unit->id, 'is_published' => true]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        $outsider = $this->createUserWithRole(UserRole::Student);

        return [$teacher, $course, $lesson, $student, $outsider];
    }

    public function test_student_asks_and_staff_answers_in_a_thread(): void
    {
        [$teacher, , $lesson, $student] = $this->lessonScenario();

        $qRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'Why is the map inverted?'])
            ->assertStatus(201);
        $questionId = $qRes->json('data.id');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/lesson-questions/{$questionId}/replies", ['body' => 'It is a south-up projection.'])
            ->assertStatus(201);

        $list = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/lessons/{$lesson->id}/questions")->assertStatus(200);
        $items = $list->json('data');
        $this->assertSame('Why is the map inverted?', $items[0]['body']);
        $this->assertSame('It is a south-up projection.', $items[0]['replies'][0]['body']);
    }

    public function test_non_enrolled_student_cannot_read_or_post(): void
    {
        [, , $lesson, , $outsider] = $this->lessonScenario();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/lessons/{$lesson->id}/questions")->assertStatus(403);
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'hi'])->assertStatus(403);
    }

    public function test_moderation_is_soft_and_keeps_the_thread(): void
    {
        [$teacher, , $lesson, $student] = $this->lessonScenario();
        $qRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'remove me'])->assertStatus(201);
        $questionId = $qRes->json('data.id');

        // Staff moderates — the row stays with is_deleted + deleted_by.
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/lesson-questions/{$questionId}")->assertStatus(200);

        $row = LessonQuestion::findOrFail($questionId);
        $this->assertTrue($row->is_deleted);
        $this->assertSame($teacher->id, $row->deleted_by);
        $this->assertSame('remove me', $row->body, 'Raw content preserved for audit');

        $list = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/lessons/{$lesson->id}/questions")->assertStatus(200);
        $this->assertSame('[removed]', $list->json('data.0.body'));
    }

    public function test_students_cannot_moderate_others_posts(): void
    {
        [, , $lesson, $student, $outsider] = $this->lessonScenario();
        $qRes = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'mine'])->assertStatus(201);

        $other = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/student/courses/{$lesson->unit->course_id}/enroll")->assertStatus(201);
        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/v1/lesson-questions/{$qRes->json('data.id')}")->assertStatus(403);
    }
}
