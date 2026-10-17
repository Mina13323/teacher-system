<?php

namespace Tests\Feature\Lesson;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Tests\Feature\ApiTestCase;

/**
 * P0-6: GET/PUT /student/lessons/{id}/progress return the lesson (with its
 * body text), so they follow the canonical LessonPolicy::access rules — the
 * same as GET /student/lessons/{id}. Denied responses carry no lesson content,
 * and a roadmap-locked lesson cannot be marked complete to unlock the next.
 */
class LessonProgressAuthorizationTest extends ApiTestCase
{
    private const SECRET = 'Protected lesson body text.';

    private User $teacher;

    private Course $course;

    private Lesson $first;

    private Lesson $second;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->course = $this->createCourse($this->teacher, ['status' => 'published']);
        $unit = $this->createUnit($this->course);
        $this->first = Lesson::factory()->create(['unit_id' => $unit->id, 'position' => 1, 'is_published' => true, 'content' => 'First lesson body.']);
        $this->second = Lesson::factory()->create(['unit_id' => $unit->id, 'position' => 2, 'is_published' => true, 'content' => self::SECRET]);
        $this->student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($this->student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$this->course->id}/enroll")
            ->assertStatus(201);
    }

    private function getProgress(User $user, Lesson $lesson)
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/v1/student/lessons/{$lesson->id}/progress");
    }

    private function putProgress(User $user, Lesson $lesson, array $payload = ['progress_percentage' => 100, 'completed' => true])
    {
        return $this->actingAs($user, 'sanctum')->putJson("/api/v1/student/lessons/{$lesson->id}/progress", $payload);
    }

    private function assertDeniedWithoutContent($response): void
    {
        $response->assertStatus(403)->assertJsonPath('success', false);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertNull($response->json('data'));
    }

    public function test_an_enrolled_student_reads_and_updates_progress_for_an_accessible_lesson(): void
    {
        $this->getProgress($this->student, $this->second)
            ->assertOk()
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.lesson.content', self::SECRET);

        $this->putProgress($this->student, $this->second, ['progress_percentage' => 40])
            ->assertOk()
            ->assertJsonPath('data.progress_percentage', 40);
        $this->putProgress($this->student, $this->second)
            ->assertOk()
            ->assertJsonPath('data.completed', true);
    }

    public function test_a_student_who_is_not_enrolled_is_denied(): void
    {
        $outsider = $this->createUserWithRole(UserRole::Student);

        $this->assertDeniedWithoutContent($this->getProgress($outsider, $this->second));
        $this->assertDeniedWithoutContent($this->putProgress($outsider, $this->second));
        $this->assertSame(0, LessonProgress::where('student_id', $outsider->id)->count());
    }

    public function test_a_roadmap_locked_lesson_is_denied_and_cannot_be_completed_to_unlock_the_next(): void
    {
        $this->course->forceFill(['roadmap_enforced' => true])->save();

        // Same answer as the canonical lesson endpoint.
        $this->actingAs($this->student, 'sanctum')->getJson("/api/v1/student/lessons/{$this->second->id}")->assertStatus(403);
        $this->assertDeniedWithoutContent($this->getProgress($this->student, $this->second));
        $this->assertDeniedWithoutContent($this->putProgress($this->student, $this->second));
        $this->assertFalse(LessonProgress::where('student_id', $this->student->id)->where('lesson_id', $this->second->id)->exists());

        // Completing the first lesson legitimately unlocks the second.
        $this->putProgress($this->student, $this->first)->assertOk()->assertJsonPath('data.completed', true);
        $this->getProgress($this->student, $this->second)->assertOk()->assertJsonPath('data.lesson.content', self::SECRET);
    }

    public function test_an_unpublished_lesson_or_course_follows_the_canonical_policy(): void
    {
        $this->second->forceFill(['is_published' => false])->save();
        $this->assertDeniedWithoutContent($this->getProgress($this->student, $this->second));
        $this->assertDeniedWithoutContent($this->putProgress($this->student, $this->second));
        $this->second->forceFill(['is_published' => true])->save();

        $this->course->forceFill(['status' => 'draft'])->save();
        $this->assertDeniedWithoutContent($this->getProgress($this->student, $this->second));
        $this->assertDeniedWithoutContent($this->putProgress($this->student, $this->second));
    }

    public function test_a_student_without_lesson_access_is_denied(): void
    {
        $this->student->forceFill(['can_access_lessons' => false])->save();
        $student = $this->student->fresh();

        $this->assertDeniedWithoutContent($this->getProgress($student, $this->second));
        $this->assertDeniedWithoutContent($this->putProgress($student, $this->second));
    }

    public function test_staff_are_not_given_new_access(): void
    {
        // The progress endpoints stay student-only for teachers, as before.
        $this->getProgress($this->teacher, $this->second)->assertStatus(403);
        $this->putProgress($this->teacher, $this->second)->assertStatus(403);
    }

    private function roadmapStatuses(): array
    {
        $units = $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$this->course->id}")->assertOk()->json('data.units');

        return collect($units)->flatMap(fn ($unit) => $unit['lessons'])->pluck('status', 'id')->all();
    }

    public function test_without_an_enforced_roadmap_every_lesson_is_open_on_the_course_page(): void
    {
        $this->putProgress($this->student, $this->first, ['progress_percentage' => 40, 'completed' => false])->assertOk();

        $statuses = $this->roadmapStatuses();
        $this->assertSame('in_progress', $statuses[$this->first->id]);
        $this->assertSame('available', $statuses[$this->second->id]);
        $this->getProgress($this->student, $this->second)->assertOk();
    }

    public function test_an_enforced_roadmap_locks_a_lesson_until_the_previous_one_is_completed(): void
    {
        $this->course->forceFill(['roadmap_enforced' => true])->save();

        $this->assertSame('locked', $this->roadmapStatuses()[$this->second->id]);

        $this->putProgress($this->student, $this->first)->assertOk();
        $this->assertSame('available', $this->roadmapStatuses()[$this->second->id]);
        $this->getProgress($this->student, $this->second)->assertOk();
    }
}
