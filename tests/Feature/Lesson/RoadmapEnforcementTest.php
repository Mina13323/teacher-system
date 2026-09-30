<?php

namespace Tests\Feature\Lesson;

use App\Enums\UserRole;
use App\Models\LessonProgress;
use Tests\Feature\ApiTestCase;

/**
 * PHASE 4 §35 — Roadmap enforcement is OPT-IN per course. Default
 * (roadmap_enforced = false) keeps today's informational behaviour; enabling
 * it denies access to later lessons until the previous one is completed.
 * Staff preview always works.
 */
class RoadmapEnforcementTest extends ApiTestCase
{
    private function twoLessonCourse(bool $enforced): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $course->forceFill(['roadmap_enforced' => $enforced])->save();
        $unit = $this->createUnit($course);
        $first = \App\Models\Lesson::factory()->create(['unit_id' => $unit->id, 'position' => 1, 'is_published' => true]);
        $second = \App\Models\Lesson::factory()->create(['unit_id' => $unit->id, 'position' => 2, 'is_published' => true]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        return [$teacher, $course, $first, $second, $student];
    }

    public function test_default_courses_stay_informational(): void
    {
        [, , , $second, $student] = $this->twoLessonCourse(false);

        // No progress at all, yet lesson 2 is readable — informational roadmap.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$second->id}")->assertStatus(200);
    }

    public function test_enforced_course_locks_until_previous_lesson_completed(): void
    {
        [$teacher, , $first, $second, $student] = $this->twoLessonCourse(true);

        // Locked: lesson 2 before lesson 1 is completed.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$second->id}")->assertStatus(403);
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$first->id}")->assertStatus(200);

        // Complete lesson 1 -> lesson 2 unlocks.
        LessonProgress::create([
            'student_id' => $student->id,
            'lesson_id' => $first->id,
            'completed' => true,
            'progress_percentage' => 100,
        ]);
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$second->id}")->assertStatus(200);
    }

    public function test_staff_preview_bypasses_enforcement(): void
    {
        [$teacher, , , $second] = $this->twoLessonCourse(true);

        // The teacher of the course can always open the lesson (update ability).
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/lessons/{$second->id}")->assertStatus(200);
    }
}
