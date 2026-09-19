<?php

namespace Tests\Feature\Exam;

use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\Unit;
use Tests\Feature\ApiTestCase;

class ExamContentScopeTest extends ApiTestCase
{
    public function test_teacher_can_target_an_exam_at_a_lesson_or_multiple_units(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $firstUnit = Unit::factory()->create(['course_id' => $course->id]);
        $secondUnit = Unit::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['unit_id' => $firstUnit->id]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Lesson quiz', 'lesson_id' => $lesson->id,
            ])->assertCreated()
            ->assertJsonPath('data.scope', 'lesson')
            ->assertJsonPath('data.lesson_id', $lesson->id);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Unit review', 'unit_ids' => [$firstUnit->id, $secondUnit->id],
            ])->assertCreated()
            ->assertJsonPath('data.scope', 'units')
            ->assertJsonPath('data.unit_ids', [$firstUnit->id, $secondUnit->id]);
    }

    public function test_teacher_cannot_target_content_from_another_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $otherUnit = Unit::factory()->create();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/exams", [
                'title' => 'Invalid scope', 'unit_ids' => [$otherUnit->id],
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('unit_ids');
    }
}
