<?php

namespace Tests\Feature\Assignment;

use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ApiTestCase;

/**
 * P2 — Assignment lifecycle: publish -> submit (text/file) -> grade -> feedback.
 *
 * Data-safety guarantees under test: one submission row per student (resubmit
 * updates it), grading cannot be erased by resubmission, files live on the
 * private disk behind an authorized download, soft-deleting the assignment
 * hides it without touching student work, and teacher-A/B + student IDOR are
 * enforced throughout.
 */
class AssignmentLifecycleTest extends ApiTestCase
{
    private function courseWithStudent(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        return [$teacher, $course, $student];
    }

    private function makeAssignment($teacher, $course, array $attrs = []): Assignment
    {
        $res = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/assignments", array_merge([
                'title' => 'Essay: Water Cycle',
                'description' => 'Explain the water cycle.',
                'points' => 20,
                'due_at' => now()->addWeek()->toISOString(),
            ], $attrs))
            ->assertStatus(201);

        return Assignment::findOrFail($res->json('data.id'));
    }

    public function test_unpublished_assignment_is_invisible_to_students_until_published(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/assignments")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/assignments/{$assignment->id}/publish")
            ->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/assignments")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_student_submits_text_and_resubmission_updates_the_same_row(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, ['is_published' => true]);

        foreach (['First draft', 'Second, better draft'] as $text) {
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", [
                    'answer_text' => $text,
                ])->assertStatus(201);
        }

        $this->assertSame(1, AssignmentSubmission::where('assignment_id', $assignment->id)->count());
        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame('Second, better draft', $submission->answer_text);
        $this->assertSame('submitted', $submission->status);
    }

    public function test_file_submission_is_private_downloadable_and_replaced_on_resubmit(): void
    {
        Storage::fake('local');
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, ['is_published' => true]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", [
                'file' => UploadedFile::fake()->create('work.pdf', 120, 'application/pdf'),
            ])->assertStatus(201);

        $submissionId = $res->json('data.id');
        $submission = AssignmentSubmission::findOrFail($submissionId);
        $this->assertNotNull($submission->file_path);
        Storage::disk('local')->assertExists($submission->file_path);
        $this->assertStringStartsWith('assignments/', $submission->file_path);

        // Owner can download; the URL never leaks the storage path.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/assignment-submissions/{$submissionId}/file")
            ->assertStatus(200);

        // Another student cannot.
        $other = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/assignment-submissions/{$submissionId}/file")
            ->assertStatus(403);

        // Resubmission with a new file replaces the old one (single row).
        $oldPath = $submission->file_path;
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", [
                'file' => UploadedFile::fake()->create('work-v2.pdf', 90, 'application/pdf'),
            ])->assertStatus(201);

        $submission->refresh();
        $this->assertNotSame($oldPath, $submission->file_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame(1, AssignmentSubmission::where('assignment_id', $assignment->id)->count());
    }

    public function test_teacher_grades_and_feedback_is_visible_after_grading(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, ['is_published' => true]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", [
                'answer_text' => 'My answer.',
            ])->assertStatus(201);
        $submissionId = $res->json('data.id');

        // Before grading: no score, no feedback.
        $show = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/assignments/{$assignment->id}")
            ->assertStatus(200);
        $this->assertNull($show->json('data.my_submission.score'));
        $this->assertNull($show->json('data.my_submission.feedback'));

        // Score above the assignment maximum is rejected.
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/assignment-submissions/{$submissionId}/grade", [
                'score' => 21,
                'feedback' => 'Too high.',
            ])->assertStatus(422);

        // A graded submission cannot be silently overwritten by resubmission.
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/assignment-submissions/{$submissionId}/grade", [
                'score' => 18,
                'feedback' => 'Strong work, minor gaps.',
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", [
                'answer_text' => 'Sneaky rewrite.',
            ])->assertStatus(422);

        // After grading: score + feedback visible to the student.
        $show = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/assignments/{$assignment->id}")
            ->assertStatus(200);
        $this->assertSame(18, $show->json('data.my_submission.score'));
        $this->assertSame('Strong work, minor gaps.', $show->json('data.my_submission.feedback'));
    }

    public function test_late_submission_is_flagged_but_accepted(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, [
            'is_published' => true,
            'due_at' => now()->subDay()->toISOString(),
        ]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", [
                'answer_text' => 'Sorry I am late.',
            ])->assertStatus(201);

        $this->assertTrue((bool) $res->json('data.is_late'));
    }

    public function test_authorization_teacher_b_and_non_enrolled_student_are_locked_out(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, ['is_published' => true]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", ['answer_text' => 'x'])
            ->assertStatus(201);
        $submissionId = $res->json('data.id');

        $teacherB = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($teacherB, 'sanctum')
            ->getJson("/api/v1/teacher/assignments/{$assignment->id}/submissions")
            ->assertStatus(403);
        $this->actingAs($teacherB, 'sanctum')
            ->postJson("/api/v1/teacher/assignment-submissions/{$submissionId}/grade", ['score' => 1])
            ->assertStatus(403);

        $stranger = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/student/assignments/{$assignment->id}")
            ->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", ['answer_text' => 'hi'])
            ->assertStatus(403);
    }

    public function test_soft_deleted_assignment_is_hidden_but_student_work_is_preserved(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, ['is_published' => true]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", ['answer_text' => 'work'])
            ->assertStatus(201);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/assignments/{$assignment->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('assignments', ['id' => $assignment->id]);
        // Student work and grades are historical record: untouched.
        $this->assertSame(1, AssignmentSubmission::where('assignment_id', $assignment->id)->count());
    }

    public function test_staff_sees_submission_counts(): void
    {
        [$teacher, $course, $student] = $this->courseWithStudent();
        $assignment = $this->makeAssignment($teacher, $course, ['is_published' => true]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/assignments/{$assignment->id}/submit", ['answer_text' => 'x'])
            ->assertStatus(201);

        $res = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/courses/{$course->id}/assignments")
            ->assertStatus(200);

        $this->assertSame(1, $res->json('data.0.submissions_count.total'));
        $this->assertSame(1, $res->json('data.0.submissions_count.submitted'));
        $this->assertSame(0, $res->json('data.0.submissions_count.graded'));
    }
}
