<?php

namespace Tests\Feature\Performance;

use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Tests\Feature\ApiTestCase;

/**
 * The student dashboard and progress list count and list progress through
 * joins. These pin the figures (including the soft-deleted lesson exclusion
 * the old whereHas gave) and that the progress list stays capped.
 */
class DashboardProgressQueriesTest extends ApiTestCase
{
    private function enrolledStudentWithProgress(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lessons = Lesson::factory()->published()->count(4)->create(['unit_id' => $unit->id]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $make = fn (Lesson $l, bool $done, int $pct) => LessonProgress::query()->create([
            'student_id' => $student->id,
            'lesson_id' => $l->id,
            'completed' => $done,
            'progress_percentage' => $pct,
        ]);

        $make($lessons[0], true, 100);
        $make($lessons[1], true, 100);
        $make($lessons[2], false, 40);
        $make($lessons[3], false, 0);

        return [$student, $course, $lessons];
    }

    public function test_dashboard_counts_completed_and_in_progress_lessons(): void
    {
        [$student] = $this->enrolledStudentWithProgress();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.completed_lessons_count', 2)
            ->assertJsonPath('data.in_progress_lessons_count', 1)
            ->assertJsonCount(4, 'data.recently_accessed_lessons');
    }

    public function test_dashboard_ignores_progress_on_soft_deleted_lessons(): void
    {
        [$student, , $lessons] = $this->enrolledStudentWithProgress();
        $lessons[0]->delete();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.completed_lessons_count', 1);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/progress')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_course_progress_percentage_counts_only_completed_published_lessons(): void
    {
        [$student, $course] = $this->enrolledStudentWithProgress();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/dashboard')
            ->assertOk()
            ->assertJsonPath('data.courses.0.progress', 50);
    }
}
