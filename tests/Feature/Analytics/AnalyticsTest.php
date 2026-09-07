<?php

namespace Tests\Feature\Analytics;

use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Analytics endpoints are scoped and privacy-safe: a teacher sees only their own
 * course/student data; a student sees only their own.
 */
class AnalyticsTest extends ApiTestCase
{
    use InteractsWithExams;

    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function makeStudent(): User
    {
        return $this->createUserWithRole(UserRole::Student);
    }

    /**
     * Build a published course+exam and an enrolled, submitted student.
     */
    private function buildCourseWithSubmittedStudent(array $examAttrs = []): array
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, $examAttrs);

        $student = $this->makeStudent();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)
            ->where('exam_id', $exam->id)
            ->firstOrFail();

        $question = $exam->questions()->first();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        return [$teacher, $course, $exam, $student];
    }

    public function test_teacher_overview_reflects_their_data(): void
    {
        [$teacher, , , ] = $this->buildCourseWithSubmittedStudent();

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(200)
            ->assertJsonPath('data.courses_count', 1)
            ->assertJsonPath('data.enrollments_count', 1)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.average_score', 100);
    }

    public function test_teacher_course_analytics_is_authorized_to_owner(): void
    {
        [$teacher, $course, , , ] = $this->buildCourseWithSubmittedStudent();

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/analytics/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.enrollments_count', 1)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.average_score', 100);
    }

    public function test_teacher_cannot_view_another_teachers_course_analytics(): void
    {
        [, $course, , , ] = $this->buildCourseWithSubmittedStudent();
        $otherTeacher = $this->makeTeacher();

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson("/api/v1/teacher/analytics/courses/{$course->id}")
            ->assertStatus(403);
    }

    public function test_student_can_view_their_own_analytics(): void
    {
        [, , , $student] = $this->buildCourseWithSubmittedStudent();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/analytics/me')
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.average_score', 100)
            ->assertJsonPath('data.enrollments_count', 1);
    }

    public function test_student_cannot_view_another_students_analytics(): void
    {
        [, , , $student] = $this->buildCourseWithSubmittedStudent();
        $otherStudent = $this->makeStudent();

        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/v1/teacher/analytics/students/{$student->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_view_system_overview(): void
    {
        $this->buildCourseWithSubmittedStudent();
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.students_count', 1)
            ->assertJsonPath('data.submitted_attempts_count', 1);
    }
}
