<?php

namespace Tests\Feature\Integrity\Concerns;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;

/**
 * Shared helpers for Phase 4 exam-integrity feature tests.
 */
trait InteractsWithIntegrity
{
    /**
     * Configure an exam's integrity settings as the given teacher.
     */
    protected function configureIntegrity(User $teacher, Exam $exam, array $settings): array
    {
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/integrity", $settings)
            ->assertStatus(200);

        return $settings;
    }

    /**
     * Create an enrolled student + published exam (with configured integrity)
     * and start an attempt for the student.
     *
     * @return array{0: User, 1: Course, 2: Exam, 3: User, 4: ExamAttempt}
     */
    protected function enrolledStudentWithStartedAttempt(array $examAttributes = [], array $integritySettings = []): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $examAttributes);

        if ($integritySettings) {
            $this->configureIntegrity($teacher, $exam, $integritySettings);
        }

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->firstOrFail();

        return [$student, $course, $exam, $teacher, $attempt];
    }

    /**
     * Report an integrity event on an attempt as the given student.
     */
    protected function postEvent(User $student, ExamAttempt $attempt, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/integrity-events", $payload);
    }
}
