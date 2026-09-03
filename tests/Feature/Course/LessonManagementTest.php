<?php

namespace Tests\Feature\Course;

use App\Enums\UserRole;
use App\Models\Lesson;
use Tests\Feature\ApiTestCase;

class LessonManagementTest extends ApiTestCase
{
    public function test_teacher_can_create_lesson(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $unit = $this->createUnit($course);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/units/{$unit->id}/lessons", ['title' => 'Getting Started']);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', 'Getting Started');

        $this->assertDatabaseHas('lessons', [
            'unit_id' => $unit->id,
            'title' => 'Getting Started',
        ]);
    }

    public function test_lesson_belongs_to_unit(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->create(['unit_id' => $unit->id]);

        $this->assertEquals($unit->id, $lesson->unit->id);
        $this->assertTrue($unit->lessons->contains($lesson));
    }

    public function test_teacher_can_publish_lesson(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->create(['unit_id' => $unit->id, 'is_published' => false]);

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/teacher/lessons/{$lesson->id}/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.is_published', true);

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'is_published' => true]);
    }

    public function test_unauthorized_teacher_cannot_modify_lesson(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $other = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($other);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->create(['unit_id' => $unit->id]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/lessons/{$lesson->id}", ['title' => 'Hijacked'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'title' => $lesson->title]);
    }
}
