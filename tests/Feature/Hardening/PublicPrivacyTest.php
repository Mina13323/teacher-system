<?php

namespace Tests\Feature\Hardening;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\Video;
use Tests\Feature\ApiTestCase;

/**
 * Cross-phase privacy regression tests for course endpoints.
 *
 * Courses require authentication, so unauthenticated requests must return 401.
 * For authenticated requests, course details must never expose creator email addresses,
 * internal account metadata, or internal storage paths.
 */
class PublicPrivacyTest extends ApiTestCase
{
    public function test_unauthenticated_requests_to_courses_are_rejected(): void
    {
        $course = Course::factory()->published()->create();

        $this->getJson('/api/v1/courses')->assertStatus(401);
        $this->getJson("/api/v1/courses/{$course->id}")->assertStatus(401);
    }

    public function test_course_list_does_not_leak_creator_email_or_account_metadata(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher, [
            'name' => 'Mina Walid',
            'email' => 'mina.walid@example.com',
        ]);
        $student = $this->createUserWithRole(UserRole::Student);

        Course::factory()->published()->create([
            'created_by' => $teacher->id,
            'title' => 'Public Marketing Course',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/courses')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $creator = $response->json('data.0.creator');

        $this->assertIsArray($creator);
        // Only safe display fields are present.
        $this->assertArrayHasKey('id', $creator);
        $this->assertArrayHasKey('name', $creator);
        $this->assertArrayHasKey('display_name', $creator);

        // No email / account metadata.
        $this->assertArrayNotHasKey('email', $creator);
        $this->assertArrayNotHasKey('avatar', $creator);
        $this->assertArrayNotHasKey('is_active', $creator);
        $this->assertArrayNotHasKey('roles', $creator);
        $this->assertSame('Mina W.', $creator['display_name']);
    }

    public function test_course_detail_does_not_leak_creator_email(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher, [
            'name' => 'Mina Walid',
            'email' => 'mina.walid@example.com',
        ]);
        $student = $this->createUserWithRole(UserRole::Student);

        $course = Course::factory()->published()->create([
            'created_by' => $teacher->id,
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertArrayNotHasKey('email', $response->json('data.creator'));
        $this->assertSame('Mina W.', $response->json('data.creator.display_name'));
    }

    public function test_course_detail_does_not_expose_internal_video_storage_path(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $course = Course::factory()->published()->create(['created_by' => $teacher->id]);
        $unit = Unit::factory()->create(['course_id' => $course->id, 'position' => 1]);
        $lesson = Lesson::factory()->create([
            'unit_id' => $unit->id,
            'position' => 1,
            'is_published' => true,
        ]);
        Video::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => 1,
            'is_published' => true,
            'storage_path' => 'videos/secret/internal/path.mp4',
        ]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $videos = $response->json('data.units.0.lessons.0.videos');
        $this->assertIsArray($videos);
        $this->assertArrayNotHasKey('storage_path', $videos[0]);
    }

    public function test_teacher_can_still_see_video_storage_path(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = Course::factory()->published()->create(['created_by' => $teacher->id]);
        $unit = Unit::factory()->create(['course_id' => $course->id, 'position' => 1]);
        $lesson = Lesson::factory()->create(['unit_id' => $unit->id, 'position' => 1, 'is_published' => true]);
        $video = Video::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => 1,
            'is_published' => true,
            'storage_path' => 'videos/teacher-only/path.mp4',
        ]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/videos/{$video->id}")
            ->assertStatus(200);

        $this->assertSame('videos/teacher-only/path.mp4', $response->json('data.storage_path'));
    }
}
