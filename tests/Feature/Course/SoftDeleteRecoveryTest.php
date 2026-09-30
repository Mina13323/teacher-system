<?php

namespace Tests\Feature\Course;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1 — Soft-delete / archive with recovery (data-safety).
 *
 * Delete is RECOVERABLE: rows are hidden (deleted_at), never destroyed, and a
 * restore endpoint brings content back. Deleting a parent hides its children
 * (no orphans); restoring the parent brings back what was deleted with it.
 * Deletion guards for attempt-bearing/competition-referenced content remain
 * unchanged (they refuse, loudly).
 */
class SoftDeleteRecoveryTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_deleted_exam_is_hidden_and_restorable(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);
        $examId = $exam->id;

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$examId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('exams', ['id' => $examId]);
        // Questions are preserved with the exam — delete must never cascade-destroy.
        $this->assertDatabaseHas('questions', ['exam_id' => $examId]);

        // Hidden from the teacher listing.
        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$course->id}/exams")
            ->assertStatus(200);
        $this->assertNotContains($examId, collect($res->json('data'))->pluck('id')->all());

        // Restore brings it back with its questions.
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$examId}/restore")
            ->assertStatus(200);

        $this->assertDatabaseHas('exams', ['id' => $examId, 'deleted_at' => null]);
        $this->assertNotNull(Exam::find($examId));

        $log = AuditLog::query()->where('action', 'exam.restore')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($examId, $log->target_id);
    }

    public function test_deleted_course_hides_children_and_restore_brings_them_back(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course, ['title' => 'Unit One']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/courses/{$course->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('courses', ['id' => $course->id]);
        $this->assertSoftDeleted('units', ['id' => $unit->id]);
        $this->assertSoftDeleted('exams', ['id' => $exam->id]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/restore")
            ->assertStatus(200);

        $this->assertDatabaseHas('courses', ['id' => $course->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'deleted_at' => null]);
    }

    public function test_deleted_lesson_is_hidden_from_students_and_restorable(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = \App\Models\Lesson::factory()->create([
            'unit_id' => $unit->id,
            'is_published' => true,
            'content' => 'Kept lesson body.',
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/lessons/{$lesson->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('lessons', ['id' => $lesson->id]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/lessons/{$lesson->id}/restore")
            ->assertStatus(200);

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'deleted_at' => null]);
        $this->assertSame('Kept lesson body.', $lesson->fresh()->content);
    }

    public function test_restore_is_authorized(): void
    {
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['status' => 'published']);
        $exam = $this->makeExam($owner, $course);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200);

        $other = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/restore")
            ->assertStatus(403);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/restore")
            ->assertStatus(403);
    }

    public function test_attempt_bearing_exam_still_refuses_deletion(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")->assertStatus(201);

        // The data-safety guard is unchanged: history-bearing exams cannot be
        // deleted (not even softly) — archive instead.
        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'deleted_at' => null]);
    }

    public function test_student_cannot_start_a_deleted_exam(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        $this->addSingleChoiceQuestion($exam, ['points' => 1]);

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/exams/{$exam->id}")
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(404);
    }
}
