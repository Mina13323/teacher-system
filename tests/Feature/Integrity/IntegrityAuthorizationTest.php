<?php

namespace Tests\Feature\Integrity;

use App\Enums\UserRole;
use App\Models\ExamIntegrityReview;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

class IntegrityAuthorizationTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_student_teacher_integrity_endpoint_is_forbidden_for_student(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}/integrity")
            ->assertStatus(403);
    }

    public function test_teacher_cannot_access_another_teachers_attempt_integrity(): void
    {
        [$student, , , $owner, $attempt] = $this->enrolledStudentWithStartedAttempt();
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}/integrity")
            ->assertStatus(403);

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}/integrity-events")
            ->assertStatus(403);
    }

    public function test_student_cannot_perform_teacher_review(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'CLEARED',
            ])->assertStatus(403);
    }

    public function test_unrelated_teacher_cannot_review(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($otherTeacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/integrity/review", [
                'decision' => 'CLEARED',
                'note' => 'Not my exam',
            ])->assertStatus(403);
    }
}
