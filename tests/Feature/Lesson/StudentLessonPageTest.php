<?php

namespace Tests\Feature\Lesson;

use App\Enums\UserRole;
use App\Models\Bookmark;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

/**
 * B3 — the lesson page reads lesson, attachments, progress, video list and
 * its own bookmark from GET /student/lessons/{id}?include=progress,videos,bookmark
 * instead of four requests. The combined response sits behind the same
 * LessonPolicy::access check, exposes no playable video reference, and
 * leaves the endpoint's default response unchanged.
 */
class StudentLessonPageTest extends ApiTestCase
{
    private const INCLUDE = 'progress,videos,bookmark';

    /** @return array{0: \App\Models\Course, 1: Lesson, 2: User} */
    private function enrolledLesson(array $courseAttributes = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, array_merge(['status' => 'published'], $courseAttributes));
        $unit = $this->createUnit($course);
        $lesson = Lesson::factory()->create(['unit_id' => $unit->id, 'is_published' => true, 'position' => 1, 'content' => 'Body.']);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        return [$course, $lesson, $student];
    }

    private function page(User $user, Lesson $lesson, string $include = self::INCLUDE)
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/v1/student/lessons/{$lesson->id}?include={$include}");
    }

    public function test_the_combined_response_carries_lesson_progress_videos_and_bookmark(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();
        Video::factory()->create(['lesson_id' => $lesson->id, 'position' => 2, 'is_published' => true, 'title' => 'Second']);
        Video::factory()->create(['lesson_id' => $lesson->id, 'position' => 1, 'is_published' => true, 'title' => 'First']);
        Video::factory()->create(['lesson_id' => $lesson->id, 'position' => 3, 'is_published' => false, 'title' => 'Draft']);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'completed' => false, 'progress_percentage' => 40, 'last_position_seconds' => 12]);
        $bookmark = Bookmark::create(['user_id' => $student->id, 'lesson_id' => $lesson->id]);

        $data = $this->page($student, $lesson)->assertOk()->assertJsonPath('success', true)->json('data');

        $this->assertSame($lesson->id, $data['id']);
        $this->assertSame('Body.', $data['content']);
        $this->assertSame([], $data['attachments']);
        $this->assertSame(40, $data['progress']['progress_percentage']);
        $this->assertSame(12, $data['progress']['last_position_seconds']);
        $this->assertFalse($data['progress']['completed']);
        $this->assertArrayNotHasKey('lesson', $data['progress'], 'The lesson is the response itself; not repeated.');
        $this->assertSame(['First', 'Second'], array_column($data['videos'], 'title'));
        $this->assertSame(['id' => $bookmark->id], $data['bookmark']);
    }

    public function test_the_parts_match_the_separate_endpoints(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();
        Video::factory()->count(2)->sequence(['position' => 1], ['position' => 2])->create(['lesson_id' => $lesson->id, 'is_published' => true]);
        LessonProgress::create(['student_id' => $student->id, 'lesson_id' => $lesson->id, 'completed' => true, 'progress_percentage' => 100]);

        $data = $this->page($student, $lesson)->assertOk()->json('data');
        $api = $this->actingAs($student, 'sanctum');
        $progress = $api->getJson("/api/v1/student/lessons/{$lesson->id}/progress")->assertOk()->json('data');
        $videos = $api->getJson("/api/v1/student/lessons/{$lesson->id}/videos")->assertOk()->json('data');
        $plain = $api->getJson("/api/v1/student/lessons/{$lesson->id}")->assertOk()->json('data');

        unset($progress['lesson']);
        $this->assertSame($progress, $data['progress']);
        $this->assertSame($videos, $data['videos']);
        $this->assertSame($plain, array_diff_key($data, array_flip(['progress', 'videos', 'bookmark'])));
    }

    public function test_a_student_without_progress_or_bookmark_gets_defaults(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();

        $data = $this->page($student, $lesson)->assertOk()->json('data');

        $this->assertNull($data['progress']['id']);
        $this->assertFalse($data['progress']['completed']);
        $this->assertSame(0, $data['progress']['progress_percentage']);
        $this->assertSame([], $data['videos']);
        $this->assertNull($data['bookmark']);
        $this->assertSame(0, LessonProgress::count(), 'Reading never creates a progress row.');
    }

    public function test_only_the_students_own_lesson_level_bookmark_is_reported(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();
        $video = Video::factory()->create(['lesson_id' => $lesson->id, 'is_published' => true]);
        $other = $this->createUserWithRole(UserRole::Student);
        Bookmark::create(['user_id' => $other->id, 'lesson_id' => $lesson->id]);
        Bookmark::create(['user_id' => $student->id, 'lesson_id' => $lesson->id, 'video_id' => $video->id, 'position_seconds' => 30]);

        $this->page($student, $lesson)->assertOk()->assertJsonPath('data.bookmark', null);
    }

    public function test_videos_never_carry_a_playable_reference(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();
        Video::factory()->create(['lesson_id' => $lesson->id, 'is_published' => true]);

        $video = $this->page($student, $lesson)->assertOk()->json('data.videos.0');

        $this->assertSame(['id', 'lesson_id', 'title', 'duration', 'position'], array_keys($video));
        $flat = json_encode($this->page($student, $lesson)->json('data'));
        foreach (['provider', 'storage_path', 'embed', 'url', 'token'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase('"'.$leak, $flat, "lesson page leaked {$leak}");
        }
    }

    public function test_without_include_the_response_is_unchanged(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();

        $data = $this->actingAs($student, 'sanctum')->getJson("/api/v1/student/lessons/{$lesson->id}")->assertOk()->json('data');
        $this->assertSame(
            ['id', 'unit_id', 'title', 'slug', 'description', 'content', 'position', 'is_published', 'attachments', 'created_at', 'updated_at'],
            array_keys($data)
        );

        // Unknown include names are ignored.
        $data = $this->page($student, $lesson, 'secrets,users')->assertOk()->json('data');
        $this->assertArrayNotHasKey('secrets', $data);
        $this->assertArrayNotHasKey('progress', $data);

        // Includes can be asked for one at a time.
        $data = $this->page($student, $lesson, 'videos')->assertOk()->json('data');
        $this->assertArrayHasKey('videos', $data);
        $this->assertArrayNotHasKey('progress', $data);
        $this->assertArrayNotHasKey('bookmark', $data);
    }

    public function test_access_rules_still_apply_to_the_combined_response(): void
    {
        [$course, $lesson, $student] = $this->enrolledLesson();

        // Not enrolled.
        $this->page($this->createUserWithRole(UserRole::Student), $lesson)->assertStatus(403);

        // Unauthenticated.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/student/lessons/{$lesson->id}?include=".self::INCLUDE)->assertStatus(401);

        // Lessons switched off for the student.
        $student->forceFill(['can_access_lessons' => false])->save();
        $this->page($student->fresh(), $lesson)->assertStatus(403);
        $student->forceFill(['can_access_lessons' => true])->save();

        // Unpublished lesson.
        $lesson->forceFill(['is_published' => false])->save();
        $this->page($student->fresh(), $lesson)->assertStatus(403);
        $lesson->forceFill(['is_published' => true])->save();

        // Unpublished course.
        $course->forceFill(['status' => 'draft'])->save();
        $this->page($student->fresh(), $lesson)->assertStatus(403);
    }

    public function test_a_roadmap_locked_lesson_reveals_nothing_through_the_combined_response(): void
    {
        [$course, $first, $student] = $this->enrolledLesson();
        $course->forceFill(['roadmap_enforced' => true])->save();
        $second = Lesson::factory()->create(['unit_id' => $first->unit_id, 'position' => 2, 'is_published' => true]);

        $this->page($student, $second)->assertStatus(403)->assertJsonMissingPath('data.progress');
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_videos(): void
    {
        [, $lesson, $student] = $this->enrolledLesson();
        Video::factory()->create(['lesson_id' => $lesson->id, 'is_published' => true, 'position' => 1]);
        $one = $this->countQueries(fn () => $this->page($student, $lesson)->assertOk());

        Video::factory()->count(6)->sequence(fn ($s) => ['position' => $s->index + 2])->create(['lesson_id' => $lesson->id, 'is_published' => true]);
        $seven = $this->countQueries(fn () => $this->page($student, $lesson)->assertOk());

        $this->assertSame($one, $seven);
        // One request instead of four: it costs at most three more queries
        // than the plain lesson read (progress, videos, bookmark).
        $plain = $this->countQueries(fn () => $this->actingAs($student, 'sanctum')->getJson("/api/v1/student/lessons/{$lesson->id}")->assertOk());
        $this->assertLessThanOrEqual($plain + 3, $seven);
    }

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
