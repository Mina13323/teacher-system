<?php

namespace Tests\Feature\Video;

use App\Enums\UserRole;
use App\Enums\VideoProvider;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPlaybackSession;
use Tests\Feature\ApiTestCase;

/**
 * Video content protection. Students may obtain a playable reference ONLY
 * through the protected playback endpoint, and only after passing every
 * server-side access rule. Provider metadata (id, channel, playlist, storage
 * path, URLs) is never returned through normal student resources.
 */
class VideoContentProtectionTest extends ApiTestCase
{
    private function teacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function courseWithPublishedLessonVideo(User $teacher, array $videoOverrides = []): array
    {
        $course = Course::factory()->published()->create(['created_by' => $teacher->id]);
        $unit = Unit::factory()->create(['course_id' => $course->id, 'position' => 1]);
        $lesson = Lesson::factory()->create([
            'unit_id' => $unit->id,
            'position' => 1,
            'is_published' => true,
        ]);

        $video = Video::factory()->create(array_merge([
            'lesson_id' => $lesson->id,
            'is_published' => true,
            'provider' => VideoProvider::Youtube->value,
            'provider_video_id' => 'dQw4w9WgXcQ',
            'storage_path' => 'videos/secret/internal/path.mp4',
        ], $videoOverrides));

        return [$course, $lesson, $video];
    }

    private function enrollStudent(User $student, int $courseId): void
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$courseId}/enroll")
            ->assertStatus(201);
    }

    // 1. Authorized published video is playable with minimum metadata.
    public function test_student_can_access_authorized_published_video(): void
    {
        $teacher = $this->teacher();
        [$course, $lesson, $video] = $this->courseWithPublishedLessonVideo($teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course->id);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.playback.provider', 'youtube')
            ->assertJsonStructure([
                'data' => [
                    'video' => ['id', 'lesson_id', 'title', 'duration'],
                    'playback' => ['provider', 'media_ref', 'token', 'expires_at'],
                    'protection',
                    'watermark',
                ],
            ]);

        // A real session row was created scoped to this student.
        $this->assertDatabaseHas('video_playback_sessions', [
            'video_id' => $video->id,
            'student_id' => $student->id,
        ]);
    }

    public function test_student_video_index_returns_only_minimal_metadata(): void
    {
        $teacher = $this->teacher();
        [$course, $lesson, $video] = $this->courseWithPublishedLessonVideo($teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course->id);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}/videos")
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $videos = $response->json('data.data');
        $this->assertIsArray($videos);
        $this->assertArrayNotHasKey('media_ref', $videos[0]);
        $this->assertArrayNotHasKey('provider', $videos[0]);
        $this->assertArrayNotHasKey('provider_video_id', $videos[0]);
        $this->assertArrayNotHasKey('storage_path', $videos[0]);
        $this->assertArrayNotHasKey('url', $videos[0]);
    }

    // 2. Student cannot access another teacher's video (different course they are not enrolled in).
    public function test_student_cannot_access_another_teachers_video(): void
    {
        $teacherA = $this->teacher();
        $teacherB = $this->teacher();
        [$courseA] = $this->courseWithPublishedLessonVideo($teacherA);
        [, , $videoB] = $this->courseWithPublishedLessonVideo($teacherB);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $courseA->id);

        // Enrolled only in courseA, so courseB's video is off-limits.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/videos/{$videoB->id}/playback")
            ->assertStatus(403);
    }

    // 3. Student cannot access a video from a course they are not enrolled in.
    public function test_student_cannot_access_unenrolled_video(): void
    {
        $teacher = $this->teacher();
        [, , $video] = $this->courseWithPublishedLessonVideo($teacher);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->assertStatus(403);
    }

    // 4. Unpublished videos are not reachable.
    public function test_student_cannot_access_unpublished_video(): void
    {
        $teacher = $this->teacher();
        [$course, $lesson, $video] = $this->courseWithPublishedLessonVideo($teacher, [
            'is_published' => false,
        ]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course->id);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->assertStatus(403);
    }

    // 5. Playback sessions are always scoped to the authenticated student (no cross-student IDOR).
    public function test_playback_session_is_scoped_to_authenticated_student(): void
    {
        $teacher = $this->teacher();
        [$course, , $video] = $this->courseWithPublishedLessonVideo($teacher);

        $studentA = $this->createUserWithRole(UserRole::Student);
        $studentB = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($studentA, $course->id);
        $this->enrollStudent($studentB, $course->id);

        $tokenA = $this->actingAs($studentA, 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->json('data.playback.token');

        $tokenB = $this->actingAs($studentB, 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->json('data.playback.token');

        $this->assertNotSame($tokenA, $tokenB);

        // Each token belongs to only its owner; no endpoint exposes another
        // student's session. Verifying ownership conservatively.
        $this->assertSame(1, VideoPlaybackSession::where('token', $tokenA)->where('student_id', $studentA->id)->count());
        $this->assertSame(1, VideoPlaybackSession::where('token', $tokenB)->where('student_id', $studentB->id)->count());
        $this->assertSame(0, VideoPlaybackSession::where('token', $tokenB)->where('student_id', $studentA->id)->count());
    }

    // 6/7/8/9. Provider identifiers / URLs / storage path are never exposed.
    public function test_student_cannot_obtain_channel_id_or_provider_urls(): void
    {
        $teacher = $this->teacher();
        [$course, , $video] = $this->courseWithPublishedLessonVideo($teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course->id);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->assertStatus(201);

        $json = $response->json('data');
        $flat = json_encode($json);

        // No channel/account/playlist identifiers, no page/embed/download URLs.
        foreach (['channel_id', 'channel_url', 'playlist', 'embed_url', 'url', 'download', 'storage_path', 'provider_video_id'] as $sensitive) {
            $this->assertStringNotContainsStringIgnoringCase($sensitive, $flat, "playback response leaked: {$sensitive}");
        }
    }

    // 10. Deactivated student cannot obtain new playback access.
    public function test_deactivated_student_cannot_obtain_playback_access(): void
    {
        $teacher = $this->teacher();
        [$course, , $video] = $this->courseWithPublishedLessonVideo($teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course->id);

        $student->update(['is_active' => false]);

        $this->actingAs($student->fresh(), 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->assertStatus(403);
    }

    // 11. Cancelled enrollment cannot obtain new playback access.
    public function test_cancelled_enrollment_cannot_obtain_playback_access(): void
    {
        $teacher = $this->teacher();
        [$course, , $video] = $this->courseWithPublishedLessonVideo($teacher);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course->id);

        Enrollment::where('student_id', $student->id)
            ->where('course_id', $course->id)
            ->update(['status' => 'cancelled']);

        $this->actingAs($student->fresh(), 'sanctum')
            ->getJson("/api/v1/student/videos/{$video->id}/playback")
            ->assertStatus(403);
    }

    // 12/15. Teacher can manage their own video (including provider fields).
    public function test_teacher_can_manage_owned_video(): void
    {
        $teacher = $this->teacher();
        [$course, $lesson] = $this->courseWithPublishedLessonVideo($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/videos", [
                'title' => 'Intro',
                'provider' => 'youtube',
                'provider_video_id' => 'abc123',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.provider', 'youtube')
            ->assertJsonPath('data.provider_video_id', 'abc123');
    }

    // 13. Teacher cannot manage another teacher's videos.
    public function test_teacher_cannot_manage_another_teachers_video(): void
    {
        $owner = $this->teacher();
        [, , $video] = $this->courseWithPublishedLessonVideo($owner);
        $other = $this->teacher();

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/teacher/videos/{$video->id}", ['title' => 'Hack'])
            ->assertStatus(403);
    }

    // 14. Admin follows existing policy (can view any video).
    public function test_admin_can_view_any_video(): void
    {
        $owner = $this->teacher();
        [, , $video] = $this->courseWithPublishedLessonVideo($owner);
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/teacher/videos/{$video->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.provider_video_id', 'dQw4w9WgXcQ');
    }

    // 15. Changing the video id does not bypass authorization (IDOR).
    public function test_changing_video_id_does_not_bypass_authorization(): void
    {
        $teacher = $this->teacher();
        [$courseA, , $videoA] = $this->courseWithPublishedLessonVideo($teacher);
        [, , $videoB] = $this->courseWithPublishedLessonVideo($teacher);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $courseA->id);

        // Enrolled in courseA; videoB belongs to a different lesson/course.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/videos/{$videoB->id}/playback")
            ->assertStatus(403);
    }
}
