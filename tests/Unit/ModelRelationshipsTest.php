<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_has_many_units(): void
    {
        $course = Course::factory()->create();
        $unit = Unit::factory()->create(['course_id' => $course->id]);

        $this->assertTrue($course->units->contains($unit));
        $this->assertEquals($course->id, $unit->course->id);
    }

    public function test_unit_has_many_lessons(): void
    {
        $unit = Unit::factory()->create();
        $lesson = Lesson::factory()->create(['unit_id' => $unit->id]);

        $this->assertTrue($unit->lessons->contains($lesson));
        $this->assertEquals($unit->id, $lesson->unit->id);
    }

    public function test_lesson_has_many_videos(): void
    {
        $lesson = Lesson::factory()->create();
        $video = Video::factory()->create(['lesson_id' => $lesson->id]);

        $this->assertTrue($lesson->videos->contains($video));
        $this->assertEquals($lesson->id, $video->lesson->id);
    }

    public function test_student_has_many_enrollments(): void
    {
        $student = User::factory()->create();
        $enrollment = Enrollment::factory()->create(['student_id' => $student->id]);

        $this->assertTrue($student->enrollments->contains($enrollment));
        $this->assertEquals($student->id, $enrollment->student->id);
        $this->assertNotNull($enrollment->course);
    }

    public function test_student_has_many_lesson_progress(): void
    {
        $student = User::factory()->create();
        $progress = LessonProgress::factory()->create(['student_id' => $student->id]);

        $this->assertTrue($student->lessonProgress->contains($progress));
        $this->assertEquals($student->id, $progress->student->id);
        $this->assertNotNull($progress->lesson);
    }

    public function test_course_creator_relationship(): void
    {
        $teacher = User::factory()->create();
        $course = Course::factory()->create(['created_by' => $teacher->id]);

        $this->assertEquals($teacher->id, $course->creator->id);
        $this->assertTrue($course->isOwnedBy($teacher));
    }

    public function test_course_has_many_enrollments(): void
    {
        $course = Course::factory()->create();
        Enrollment::factory()->create(['course_id' => $course->id]);

        $this->assertCount(1, $course->enrollments);
    }

    public function test_lesson_has_many_progress_records(): void
    {
        $lesson = Lesson::factory()->create();
        LessonProgress::factory()->create(['lesson_id' => $lesson->id]);

        $this->assertCount(1, $lesson->progressRecords);
    }
}
