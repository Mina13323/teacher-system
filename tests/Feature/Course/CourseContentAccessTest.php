<?php

namespace Tests\Feature\Course;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\Video;
use Tests\Feature\ApiTestCase;

class CourseContentAccessTest extends ApiTestCase
{
    /** @return array{Course, Lesson, Video} */
    private function publishedCourseWithContent(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = Course::factory()->published()->create(['created_by' => $teacher->id]);
        $unit = Unit::factory()->create(['course_id' => $course->id, 'position' => 1]);
        $lesson = Lesson::factory()->published()->create([
            'unit_id' => $unit->id,
            'position' => 1,
            'title' => 'Preview lesson title',
            'content' => 'Enrollment-gated lesson body.',
        ]);
        $video = Video::factory()->create([
            'lesson_id' => $lesson->id,
            'position' => 1,
            'is_published' => true,
            'provider' => 'youtube',
            'provider_video_id' => 'private-provider-id',
            'storage_path' => 'videos/private/course-1.mp4',
        ]);

        return [$course, $lesson, $video];
    }

    public function test_non_enrolled_student_gets_only_safe_course_preview_metadata(): void
    {
        [$course, , $video] = $this->publishedCourseWithContent();
        $student = $this->createUserWithRole(UserRole::Student);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', false)
            ->assertJsonPath('data.units.0.lessons.0.title', 'Preview lesson title');

        $lessonPreview = $response->json('data.units.0.lessons.0');
        $videoPreview = $lessonPreview['videos'][0] ?? [];

        $this->assertArrayNotHasKey('content', $lessonPreview);
        $this->assertArrayNotHasKey('provider', $videoPreview);
        $this->assertArrayNotHasKey('provider_video_id', $videoPreview);
        $this->assertArrayNotHasKey('storage_path', $videoPreview);
        $this->assertStringNotContainsString('Enrollment-gated lesson body.', $response->getContent());
        $this->assertStringNotContainsString($video->storage_path, $response->getContent());

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$video->lesson_id}")
            ->assertForbidden();
    }

    public function test_enrollment_unlocks_the_gated_lesson_endpoint_not_the_catalog_preview(): void
    {
        [$course, $lesson] = $this->publishedCourseWithContent();
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertCreated();

        $preview = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.is_enrolled', true);
        $this->assertArrayNotHasKey('content', $preview->json('data.units.0.lessons.0'));

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('data.content', 'Enrollment-gated lesson body.');
    }

    public function test_generic_course_preview_never_grants_staff_video_fields(): void
    {
        [$course, , $video] = $this->publishedCourseWithContent();
        $unrelatedTeacher = $this->createUserWithRole(UserRole::Teacher);

        $response = $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/courses/{$course->id}")
            ->assertOk();

        $videoPreview = $response->json('data.units.0.lessons.0.videos.0');
        $this->assertArrayNotHasKey('provider', $videoPreview);
        $this->assertArrayNotHasKey('provider_video_id', $videoPreview);
        $this->assertArrayNotHasKey('storage_path', $videoPreview);
        $this->assertStringNotContainsString($video->storage_path, $response->getContent());

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/teacher/videos/{$video->id}")
            ->assertForbidden();
    }
}
