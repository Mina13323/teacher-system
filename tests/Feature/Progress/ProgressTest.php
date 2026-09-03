<?php

namespace Tests\Feature\Progress;

use App\Enums\UserRole;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Tests\Feature\ApiTestCase;

class ProgressTest extends ApiTestCase
{
    private function publishedCourseWithLessons(int $lessonCount = 1): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        $lessons = collect();
        for ($i = 0; $i < $lessonCount; $i++) {
            $unit = $this->createUnit($course);
            $lessons->push(Lesson::factory()->published()->create(['unit_id' => $unit->id]));
        }

        return [$course, $teacher, $lessons];
    }

    private function enroll(UserRole $role, $course): mixed
    {
        $student = $this->createUserWithRole($role);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        return $student;
    }

    public function test_student_can_update_own_progress(): void
    {
        [$course, , $lessons] = $this->publishedCourseWithLessons();
        $student = $this->enroll(UserRole::Student, $course);
        $lesson = $lessons->first();

        $response = $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", [
                'progress_percentage' => 50,
                'last_position_seconds' => 120,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.progress_percentage', 50)
            ->assertJsonPath('data.completed', false);

        $this->assertDatabaseHas('lesson_progress', [
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'progress_percentage' => 50,
            'completed' => false,
        ]);
    }

    public function test_progress_belongs_to_authenticated_student(): void
    {
        [$course, , $lessons] = $this->publishedCourseWithLessons();
        $student = $this->enroll(UserRole::Student, $course);
        $lesson = $lessons->first();

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", ['progress_percentage' => 25])
            ->assertStatus(200);

        $this->assertSame(
            $student->id,
            LessonProgress::where('lesson_id', $lesson->id)->first()->student_id
        );
    }

    public function test_100_percent_marks_lesson_completed(): void
    {
        [$course, , $lessons] = $this->publishedCourseWithLessons();
        $student = $this->enroll(UserRole::Student, $course);
        $lesson = $lessons->first();

        $response = $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", ['progress_percentage' => 100]);

        $response->assertStatus(200)
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.progress_percentage', 100);

        $this->assertNotNull(LessonProgress::where('lesson_id', $lesson->id)->first()->completed_at);
    }

    public function test_below_100_clears_completed_at(): void
    {
        [$course, , $lessons] = $this->publishedCourseWithLessons();
        $student = $this->enroll(UserRole::Student, $course);
        $lesson = $lessons->first();

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", ['progress_percentage' => 99])
            ->assertStatus(200);
        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", ['progress_percentage' => 100])
            ->assertStatus(200);

        $response = $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", ['progress_percentage' => 50]);

        $response->assertStatus(200)
            ->assertJsonPath('data.completed', false);

        $this->assertNull(LessonProgress::where('lesson_id', $lesson->id)->first()->completed_at);
    }

    public function test_invalid_progress_values_are_rejected(): void
    {
        [$course, , $lessons] = $this->publishedCourseWithLessons();
        $student = $this->enroll(UserRole::Student, $course);
        $lesson = $lessons->first();

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lesson->id}/progress", [
                'progress_percentage' => 150,
                'last_position_seconds' => -5,
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_student_cannot_update_progress_for_unenrolled_course(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $courseA = $this->createCourse($teacher, ['status' => 'published']);
        $courseB = $this->createCourse($teacher, ['status' => 'published']);

        $unitB = $this->createUnit($courseB);
        $lessonB = Lesson::factory()->published()->create(['unit_id' => $unitB->id]);

        $student = $this->enroll(UserRole::Student, $courseA);

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lessonB->id}/progress", ['progress_percentage' => 50])
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_course_progress_is_calculated_correctly(): void
    {
        [$course, , $lessons] = $this->publishedCourseWithLessons(2);
        $student = $this->enroll(UserRole::Student, $course);

        $this->actingAs($student, 'sanctum')
            ->putJson("/api/v1/student/lessons/{$lessons->first()->id}/progress", ['progress_percentage' => 100])
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.progress', 50);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}/roadmap")
            ->assertStatus(200)
            ->assertJsonPath('data.progress', 50);
    }
}
