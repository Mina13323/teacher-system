<?php

namespace Tests\Feature\Analytics;

use App\Enums\ExamAttemptStatus;
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

    // ---- Regression: analytics used to count only `submitted` --------------
    //
    // An attempt moves to `grading` when it has essays and to `published` once
    // grades are released. Filtering on `submitted` alone silently dropped every
    // attempt that had been through essay grading, so every number on every
    // analytics screen was understated.

    public function test_a_published_attempt_is_counted_in_the_teacher_overview(): void
    {
        [$teacher, , , $student] = $this->buildCourseWithSubmittedStudent();

        ExamAttempt::where('student_id', $student->id)
            ->update(['status' => ExamAttemptStatus::Published->value]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.scored_attempts_count', 1)
            ->assertJsonPath('data.average_score', 100);
    }

    public function test_a_published_attempt_appears_in_the_student_history(): void
    {
        [, , , $student] = $this->buildCourseWithSubmittedStudent();

        ExamAttempt::where('student_id', $student->id)
            ->update(['status' => ExamAttemptStatus::Published->value]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/analytics/me')
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.history.0.percentage', 100);
    }

    /**
     * While an attempt awaits essay grading its percentage is partial — the
     * ungraded essays count as zero against the full point total. It must count
     * as an attempt but never as a score.
     */
    public function test_an_attempt_awaiting_grading_counts_but_does_not_skew_the_average(): void
    {
        [$teacher, , , $student] = $this->buildCourseWithSubmittedStudent();

        ExamAttempt::where('student_id', $student->id)
            ->update(['status' => ExamAttemptStatus::Grading->value, 'percentage' => 20]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.pending_grading_count', 1)
            ->assertJsonPath('data.scored_attempts_count', 0)
            ->assertJsonPath('data.average_score', null)
            ->assertJsonPath('data.pass_rate', null);
    }

    /**
     * The score is written to the attempt at submit time, but a student must not
     * see it before the teacher releases grades.
     */
    public function test_a_student_does_not_see_an_unpublished_score_in_their_analytics(): void
    {
        [, , , $student] = $this->buildCourseWithSubmittedStudent();

        ExamAttempt::where('student_id', $student->id)->update(['grades_published_at' => null]);

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/analytics/me')
            ->assertStatus(200)
            ->assertJsonPath('data.attempts_count', 1)
            ->assertJsonPath('data.average_score', null)
            ->assertJsonPath('data.best_score', null)
            ->assertJsonPath('data.history.0.percentage', null)
            ->assertJsonPath('data.history.0.grades_published', false);

        // The attempt is still listed — just without a number on it.
        $this->assertCount(1, $response->json('data.history'));
    }

    public function test_a_teacher_still_sees_an_unpublished_score_in_student_analytics(): void
    {
        [$teacher, , , $student] = $this->buildCourseWithSubmittedStudent();

        ExamAttempt::where('student_id', $student->id)->update(['grades_published_at' => null]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/analytics/students/{$student->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.average_score', 100)
            ->assertJsonPath('data.history.0.percentage', 100);
    }

    public function test_admin_overview_counts_published_attempts(): void
    {
        [, , , $student] = $this->buildCourseWithSubmittedStudent();
        $admin = $this->createUserWithRole(UserRole::Admin);

        ExamAttempt::where('student_id', $student->id)
            ->update(['status' => ExamAttemptStatus::Published->value]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('data.submitted_attempts_count', 1)
            ->assertJsonPath('data.average_score', 100);
    }

    /**
     * The course-level groupings — top performers, weak areas, cohort lesson
     * completion — are computed with SQL GROUP BY and a LIMIT. Every other test
     * here uses a single student with a single attempt, which would exercise
     * none of that, so this builds a real cohort with distinct scores.
     */
    public function test_course_analytics_groups_a_multi_student_cohort_correctly(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        foreach ([100, 60, 20] as $percentage) {
            $student = $this->makeStudent();

            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/courses/{$course->id}/enroll")
                ->assertStatus(201);

            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/exams/{$exam->id}/start")
                ->assertStatus(201);

            ExamAttempt::where('student_id', $student->id)
                ->where('exam_id', $exam->id)
                ->update([
                    'status' => ExamAttemptStatus::Submitted->value,
                    'percentage' => $percentage,
                ]);
        }

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/analytics/courses/{$course->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.enrollments_count', 3)
            ->assertJsonPath('data.students_count', 3)
            ->assertJsonPath('data.attempts_count', 3)
            ->assertJsonPath('data.scored_attempts_count', 3)
            // (100 + 60 + 20) / 3
            ->assertJsonPath('data.average_score', 60)
            // Ordered best-first by the SQL ORDER BY, capped at five.
            ->assertJsonPath('data.top_performers.0.average', 100)
            ->assertJsonPath('data.top_performers.1.average', 60)
            ->assertJsonPath('data.top_performers.2.average', 20)
            ->assertJsonCount(3, 'data.top_performers')
            // The single exam carries the cohort mean.
            ->assertJsonPath('data.weak_areas.0.average', 60)
            ->assertJsonPath('data.weak_areas.0.attempts', 3)
            // No lessons in this course, so completion is zero rather than null.
            ->assertJsonPath('data.average_lesson_completion', 0);
    }

    /**
     * A distinct student count must not double-count a student enrolled in
     * several of the teacher's courses.
     */
    public function test_overview_counts_a_student_once_across_multiple_courses(): void
    {
        $teacher = $this->makeTeacher();
        $courseA = $this->createCourse($teacher, ['status' => 'published']);
        $courseB = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->makeStudent();

        foreach ([$courseA, $courseB] as $course) {
            $this->actingAs($student, 'sanctum')
                ->postJson("/api/v1/student/courses/{$course->id}/enroll")
                ->assertStatus(201);
        }

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(200)
            ->assertJsonPath('data.courses_count', 2)
            ->assertJsonPath('data.enrollments_count', 2)
            ->assertJsonPath('data.students_count', 1);
    }

    /**
     * The admin analytics screen reuses these endpoints. An admin's
     * staffOwnerIds() is null, so the same overview must aggregate every
     * teacher's data rather than returning the empty set a scoped query would.
     */
    public function test_an_admin_overview_is_fleet_wide_not_scoped_to_one_teacher(): void
    {
        $this->buildCourseWithSubmittedStudent();
        $this->buildCourseWithSubmittedStudent();

        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/teacher/analytics/overview')
            ->assertStatus(200)
            ->assertJsonPath('data.courses_count', 2)
            ->assertJsonPath('data.enrollments_count', 2)
            ->assertJsonPath('data.attempts_count', 2);
    }

    /**
     * An admin may also drill into a course they do not own, which is what the
     * admin course selector depends on.
     */
    public function test_an_admin_can_read_course_analytics_for_another_teachers_course(): void
    {
        [, $course, , ] = $this->buildCourseWithSubmittedStudent();

        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/teacher/analytics/courses/{$course->id}")
            ->assertStatus(200);
    }
}
