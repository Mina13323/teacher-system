<?php

namespace Tests\Feature\Progress;

use App\Enums\UserRole;
use App\Models\Lesson;
use Tests\Feature\ApiTestCase;

class RoadmapTest extends ApiTestCase
{
    public function test_roadmap_returns_correct_units_and_states(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $unitA = $this->createUnit($course);
        $unitB = $this->createUnit($course);

        $lessonA1 = Lesson::factory()->published()->create(['unit_id' => $unitA->id]);
        $lessonB1 = Lesson::factory()->published()->create(['unit_id' => $unitB->id]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        // Complete the first lesson.
        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lessonA1->id}/progress", ['progress_percentage' => 100])
            ->assertStatus(200);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}/roadmap");

        $response->assertStatus(200)
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.progress', 50);

        $units = $response->json('data.units');
        $this->assertCount(2, $units);

        $this->assertSame('completed', $units[0]['lessons'][0]['status']);
        $this->assertSame(100, $units[0]['progress']);
        $this->assertSame('completed', $units[0]['status']);

        $this->assertSame('available', $units[1]['lessons'][0]['status']);
        $this->assertSame(0, $units[1]['progress']);
        $this->assertSame('not_started', $units[1]['status']);
    }

    public function test_roadmap_excludes_unpublished_lessons(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $unit = $this->createUnit($course);
        $published = Lesson::factory()->published()->create(['unit_id' => $unit->id]);
        Lesson::factory()->create(['unit_id' => $unit->id, 'is_published' => false]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}/roadmap");

        $response->assertStatus(200);
        $lessons = $response->json('data.units.0.lessons');
        $this->assertCount(1, $lessons);
        $this->assertSame($published->id, $lessons[0]['id']);
    }

    public function test_roadmap_marks_viewed_lesson_in_progress(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->published()->create(['unit_id' => $unit->id]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", ['progress_percentage' => 40])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}/roadmap")
            ->assertStatus(200)
            ->assertJsonPath('data.units.0.lessons.0.status', 'in_progress')
            ->assertJsonPath('data.progress', 0);
    }

    public function test_student_cannot_access_roadmap_for_unenrolled_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}/roadmap")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }
}
