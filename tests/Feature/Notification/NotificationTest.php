<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class NotificationTest extends ApiTestCase
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

    private function createManagedStudent($teacher): User
    {
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        return User::where('email', 'mina@example.com')->firstOrFail();
    }

    public function test_teacher_can_send_a_message_to_a_student_they_manage(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->createManagedStudent($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/notify", [
                'message' => 'Please review the unit 3 material.',
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.data.message', 'Please review the unit 3 material.');
    }

    public function test_teacher_cannot_notify_a_student_they_do_not_manage(): void
    {
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $student = $this->createManagedStudent($teacherB); // managed by B

        $this->actingAs($teacherA, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/notify", [
                'message' => 'Spam',
            ])->assertStatus(403);
    }

    public function test_student_can_mark_a_notification_as_read(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->createManagedStudent($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/notify", [
                'message' => 'Read me',
            ])->assertStatus(200);

        $notification = $student->notifications()->first();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(200);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $student->unreadNotifications()->count());
    }

    public function test_student_cannot_read_another_users_notification(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->createManagedStudent($teacher);
        $other = $this->makeStudent();

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/notify", [
                'message' => 'Private',
            ])->assertStatus(200);

        $notification = $student->notifications()->first();

        // Another student cannot mark it read (it is not theirs -> 404).
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(404);
    }

    public function test_submitting_an_exam_notifies_the_student(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $student = $this->makeStudent();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(201);

        $attempt = ExamAttempt::where('student_id', $student->id)->where('exam_id', $exam->id)->firstOrFail();
        $question = $exam->questions()->first();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $this->correctOption($question)->id,
            ])->assertStatus(200);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $notification = $student->notifications()
            ->where('type', \App\Notifications\ResultAvailableNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('result_available', $notification->data['type']);
    }

    public function test_publishing_an_exam_notifies_enrolled_students(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => 'draft',
        ]);
        $this->addSingleChoiceQuestion($exam);

        $student = $this->makeStudent();
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/publish")
            ->assertStatus(200);

        $notification = $student->notifications()
            ->where('type', \App\Notifications\ExamPublishedNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('exam_published', $notification->data['type']);
    }
}
