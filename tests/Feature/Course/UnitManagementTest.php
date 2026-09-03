<?php

namespace Tests\Feature\Course;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Unit;
use Tests\Feature\ApiTestCase;

class UnitManagementTest extends ApiTestCase
{
    public function test_teacher_can_create_unit(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/units", ['title' => 'Foundations']);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.title', 'Foundations');

        $this->assertDatabaseHas('units', [
            'course_id' => $course->id,
            'title' => 'Foundations',
        ]);
    }

    public function test_unit_belongs_to_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $unit = $this->createUnit($course);

        $this->assertEquals($course->id, $unit->course->id);
        $this->assertTrue($course->units->contains($unit));
    }

    public function test_teacher_can_reorder_units(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $unitA = $this->createUnit($course);
        $unitB = $this->createUnit($course);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/courses/{$course->id}/units/reorder", [
                'ordered_ids' => [$unitB->id, $unitA->id],
            ])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(1, Unit::find($unitB->id)->position);
        $this->assertSame(2, Unit::find($unitA->id)->position);
    }

    public function test_unauthorized_teacher_cannot_modify_unit(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $other = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($other);
        $unit = $this->createUnit($course);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/units/{$unit->id}", ['title' => 'Hijacked'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'title' => $unit->title]);
    }

    public function test_student_cannot_create_unit(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $course = $this->createCourse($teacher);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/units", ['title' => 'Nope'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }
}
