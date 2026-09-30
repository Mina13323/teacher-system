<?php

namespace Tests\Feature\Search;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

/**
 * PHASE 4 §34 — Role-scoped search: enrolled content for students, own
 * courses/students for staff. No cross-teacher leaks.
 */
class SearchTest extends ApiTestCase
{
    public function test_student_search_only_finds_their_enrolled_content(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published', 'title' => 'Geography Legends']);
        $unit = $this->createUnit($course);
        $lesson = \App\Models\Lesson::factory()->create([
            'unit_id' => $unit->id,
            'title' => 'Trade Winds Legends',
            'is_published' => true,
        ]);

        $foreignCourse = $this->createCourse($teacher, ['status' => 'published', 'title' => 'Secret Legends']);
        $foreignUnit = $this->createUnit($foreignCourse);
        \App\Models\Lesson::factory()->create([
            'unit_id' => $foreignUnit->id,
            'title' => 'Secret Legends Lesson',
            'is_published' => true,
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        $res = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/search?q=Legends')->assertStatus(200);

        $titles = collect($res->json('data.results'))->flatten(1)->pluck('title')->all();
        $this->assertContains('Geography Legends', $titles);
        $this->assertContains('Trade Winds Legends', $titles);
        $this->assertNotContains('Secret Legends', $titles, 'Un-enrolled course content must not leak');
        $this->assertNotContains('Secret Legends Lesson', $titles);
    }

    public function test_teacher_search_stays_inside_own_courses_and_students(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published', 'title' => 'My Own Course']);
        $student = $this->createUserWithRole(UserRole::Student, ['name' => 'Searchable Pupil']);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        // Another teacher's student must not appear.
        $teacherB = $this->createUserWithRole(UserRole::Teacher);
        $courseB = $this->createCourse($teacherB, ['status' => 'published', 'title' => 'Other Course Here']);
        $studentB = $this->createUserWithRole(UserRole::Student, ['name' => 'Hidden Pupil']);
        $this->actingAs($studentB, 'sanctum')
            ->postJson("/api/v1/student/courses/{$courseB->id}/enroll")->assertStatus(201);

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/search?q=Pupil')->assertStatus(200);
        $titles = collect($res->json('data.results'))->flatten(1)->pluck('title')->all();
        $this->assertContains('Searchable Pupil', $titles);
        $this->assertNotContains('Hidden Pupil', $titles);

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/search?q=Course')->assertStatus(200);
        $titles = collect($res->json('data.results'))->flatten(1)->pluck('title')->all();
        $this->assertContains('My Own Course', $titles);
        $this->assertNotContains('Other Course Here', $titles);
    }

    public function test_search_requires_a_query(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->getJson('/api/v1/search?q=')->assertStatus(422);
    }
}
