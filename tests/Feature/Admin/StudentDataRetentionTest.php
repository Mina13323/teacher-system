<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Feature\ApiTestCase;

class StudentDataRetentionTest extends ApiTestCase
{
    private function createStudent(array $attributes = []): User
    {
        $student = User::factory()->create(array_merge([
            'name' => 'History Student',
            'email' => 'history-student@example.test',
            'student_code' => 'HIST-001',
            'phone' => '+201012345678',
            'is_active' => true,
        ], $attributes));
        $student->assignRole(UserRole::Student->value);

        return $student;
    }

    /** @return array{Exam, ExamAttempt, ExamAnswer} */
    private function createAttemptWithAnswer(User $student, ?User $teacher = null): array
    {
        $teacher ??= $this->createUserWithRole(UserRole::Teacher);
        $course = Course::factory()->create(['created_by' => $teacher->id]);
        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
        ]);
        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
        ]);
        $question = Question::factory()->create(['exam_id' => $exam->id]);
        $answer = ExamAnswer::factory()->create([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
        ]);

        return [$exam, $attempt, $answer];
    }

    public function test_teacher_deactivation_preserves_exam_attempts_and_answers(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createStudent([
            'created_by' => $teacher->id,
        ]);
        [, $attempt, $answer] = $this->createAttemptWithAnswer($student, $teacher);

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/teacher/students/{$student->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $student->id, 'is_active' => false]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('exam_answers', ['id' => $answer->id, 'attempt_id' => $attempt->id]);
    }

    public function test_anonymization_requires_the_student_specific_confirmation_phrase(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $student = $this->createStudent();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => 'ANONYMIZE STUDENT someone-else',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'email' => 'history-student@example.test',
            'phone' => '+201012345678',
            'is_active' => true,
        ]);
    }

    public function test_anonymization_disables_account_and_preserves_enrollments_attempts_and_answers(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createStudent();
        $course = Course::factory()->create(['created_by' => $teacher->id]);
        $enrollment = Enrollment::factory()->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
        ]);
        [, $attempt, $answer] = $this->createAttemptWithAnswer($student, $teacher);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => "ANONYMIZE STUDENT {$student->id}",
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Anonymized student '.$student->id,
            'email' => 'anonymized-student-'.$student->id.'@example.invalid',
            'student_code' => null,
            'phone' => null,
            'avatar' => null,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('exam_answers', ['id' => $answer->id, 'attempt_id' => $attempt->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.anonymize',
            'target_id' => $student->id,
        ]);
    }

    public function test_exam_attempt_foreign_key_blocks_unapproved_direct_student_deletion(): void
    {
        $student = $this->createStudent();
        [, $attempt] = $this->createAttemptWithAnswer($student);

        try {
            $student->delete();
            $this->fail('Deleting a student with exam history must be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $student->id]);
            $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'student_id' => $student->id]);
        }
    }

    public function test_force_delete_requires_explicit_history_confirmation_and_deletes_only_selected_student_history(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createStudent();
        $otherStudent = $this->createStudent([
            'name' => 'Other student',
            'email' => 'other-student@example.test',
            'student_code' => 'HIST-002',
        ]);
        [, $attempt, $answer] = $this->createAttemptWithAnswer($student, $teacher);
        [, $otherAttempt, $otherAnswer] = $this->createAttemptWithAnswer($otherStudent, $teacher);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/force-delete", [
                'confirmation' => "FORCE DELETE STUDENT {$student->id}",
                'delete_academic_history' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('delete_academic_history');

        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('exam_answers', ['id' => $answer->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/force-delete", [
                'confirmation' => "FORCE DELETE STUDENT {$student->id}",
                'delete_academic_history' => true,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('exam_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseMissing('exam_answers', ['id' => $answer->id]);
        $this->assertDatabaseHas('users', ['id' => $otherStudent->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $otherAttempt->id]);
        $this->assertDatabaseHas('exam_answers', ['id' => $otherAnswer->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'exam.attempt.force_delete',
            'target_id' => $attempt->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student.force_delete',
            'target_id' => $student->id,
        ]);
    }

    public function test_batch_deactivation_is_all_or_nothing_when_one_selected_student_is_out_of_scope(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $managed = $this->createStudent([
            'name' => 'Managed student',
            'email' => 'managed-student@example.test',
            'student_code' => 'BATCH-001',
            'created_by' => $teacher->id,
        ]);
        $outOfScope = $this->createStudent([
            'name' => 'Other teacher student',
            'email' => 'other-teacher-student@example.test',
            'student_code' => 'BATCH-002',
            'created_by' => $otherTeacher->id,
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students/batch-deactivate', [
                'ids' => [$managed->id, $outOfScope->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $managed->id, 'is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $outOfScope->id, 'is_active' => true]);
    }

    public function test_staff_cannot_use_student_management_routes_on_non_student_accounts(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $assistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $teacher->id]);
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($assistant, 'sanctum')
            ->patchJson("/api/v1/teacher/students/{$otherTeacher->id}/deactivate")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $otherTeacher->id, 'is_active' => true]);
    }

    public function test_generic_delete_routes_are_not_exposed_for_teacher_or_admin_students(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $admin = $this->createUserWithRole(UserRole::Admin);
        $student = $this->createStudent();

        $teacherResponse = $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/students/{$student->id}");
        $this->assertFalse($teacherResponse->isSuccessful());

        $adminResponse = $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/students/{$student->id}");
        $this->assertFalse($adminResponse->isSuccessful());
        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_teachers_cannot_anonymize_or_force_delete_students(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $student = $this->createStudent();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => "ANONYMIZE STUDENT {$student->id}",
            ])
            ->assertForbidden();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/force-delete", [
                'confirmation' => "FORCE DELETE STUDENT {$student->id}",
                'delete_academic_history' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_anonymization_sets_the_anonymized_at_marker(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $student = $this->createStudent();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => "ANONYMIZE STUDENT {$student->id}",
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'is_active' => false,
        ]);

        // anonymized_at must be a non-null timestamp after the action.
        $fresh = \App\Models\User::find($student->id);
        $this->assertNotNull($fresh->anonymized_at, 'anonymized_at must be set after anonymization.');
        $this->assertTrue($fresh->isAnonymized(), 'isAnonymized() must return true.');
    }

    public function test_anonymized_account_cannot_be_reactivated_through_ordinary_flows(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $student = $this->createStudent();

        // Anonymize first.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => "ANONYMIZE STUDENT {$student->id}",
            ])
            ->assertOk();

        // Attempt to reactivate — must be rejected with 409.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/students/{$student->id}/activate")
            ->assertStatus(409);

        // Account must remain deactivated.
        $this->assertDatabaseHas('users', ['id' => $student->id, 'is_active' => false]);
    }

    public function test_credential_regeneration_is_blocked_for_anonymized_accounts(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $admin = $this->createUserWithRole(UserRole::Admin);
        $student = $this->createStudent(['created_by' => $teacher->id]);

        // Anonymize.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => "ANONYMIZE STUDENT {$student->id}",
            ])
            ->assertOk();

        // Credential reset must be rejected for anonymized accounts.
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(409);
    }

    public function test_password_reset_is_blocked_for_anonymized_accounts(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $student = $this->createStudent();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/anonymize", [
                'confirmation' => "ANONYMIZE STUDENT {$student->id}",
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/students/{$student->id}/reset-password", [
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
            ])
            ->assertStatus(409);
    }
}
