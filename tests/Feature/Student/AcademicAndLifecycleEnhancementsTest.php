<?php

namespace Tests\Feature\Student;

use App\Enums\AcademicSubject;
use App\Enums\AcademicYear;
use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\StudentAccessStatus;
use App\Enums\StudentCapabilityPreset;
use App\Enums\UserRole;
use App\Http\Resources\ExamResultResource;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Feature\ApiTestCase;

class AcademicAndLifecycleEnhancementsTest extends ApiTestCase
{
    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function makeAssistant(): User
    {
        return $this->createUserWithRole(UserRole::Assistant);
    }

    public function test_teacher_can_create_student_with_academic_subject_and_template_credentials(): void
    {
        $teacher = $this->makeTeacher();

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mariam Tarek',
                'phone' => '+201099887766',
                'academic_year' => AcademicYear::Secondary3->value,
                'academic_subject' => AcademicSubject::Geography->value,
                'capability_preset' => StudentCapabilityPreset::All->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Mariam Tarek')
            ->assertJsonPath('data.academic_year', AcademicYear::Secondary3->value)
            ->assertJsonPath('data.academic_subject', AcademicSubject::Geography->value)
            ->assertJsonPath('data.academic_subject_label', AcademicSubject::Geography->label());

        $studentCode = $response->json('data.student_code');
        $expectedEmail = strtolower($studentCode) . '@student.com';
        $expectedPassword = $studentCode . '2026';

        $response->assertJsonPath('credentials.login', $expectedEmail)
            ->assertJsonPath('credentials.temporary_password', $expectedPassword);

        $student = User::where('student_code', $studentCode)->first();
        $this->assertNotNull($student);
        $this->assertEquals($expectedEmail, $student->email);
        $this->assertTrue($student->must_change_password);
    }

    public function test_student_access_lifecycle_suspend_restore_allow_immediately(): void
    {
        $teacher = $this->makeTeacher();

        $createRes = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Ahmed Hassan',
                'phone' => '+201122334455',
                'academic_year' => AcademicYear::Secondary3->value,
                'academic_subject' => AcademicSubject::History->value,
            ]);

        $studentId = $createRes->json('data.id');
        $student = User::findOrFail($studentId);

        // 1. Suspend
        $suspendRes = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$studentId}/suspend");

        $suspendRes->assertStatus(200)
            ->assertJsonPath('data.access_status', StudentAccessStatus::Suspended->value);

        $this->assertEquals(StudentAccessStatus::Suspended->value, $student->fresh()->accessStatus());

        // 2. Restore
        $restoreRes = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$studentId}/restore");

        $restoreRes->assertStatus(200);
        $this->assertEquals(StudentAccessStatus::Active->value, $student->fresh()->accessStatus());

        // 3. Allow Immediately
        $allowRes = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$studentId}/allow-immediately", [
                'months' => 2,
            ]);

        $allowRes->assertStatus(200)
            ->assertJsonPath('data.access_status', StudentAccessStatus::Active->value);

        $this->assertEquals(StudentAccessStatus::Active->value, $student->fresh()->accessStatus());
        $this->assertNotNull($student->fresh()->latestAccessPeriod);
    }

    public function test_essay_question_creation_and_grading_workflow(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher, [
            'academic_year' => AcademicYear::Secondary3->value,
            'academic_subject' => AcademicSubject::Both->value,
        ]);

        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => ExamStatus::Published,
            'duration_minutes' => 60,
            'pass_percentage' => 50,
        ]);

        // Create MCQ and Essay Questions
        $mcq = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => QuestionType::SingleChoice->value,
            'points' => 5,
        ]);

        $essay = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => QuestionType::Essay->value,
            'points' => 10,
            'reference_answer' => 'Sample key points for essay reference.',
        ]);

        // Create student
        $student = $this->createUserWithRole(UserRole::Student, [
            'academic_year' => AcademicYear::Secondary3->value,
            'academic_subject' => AcademicSubject::Both->value,
            'can_take_exams' => true,
        ]);

        // Create exam attempt & attempt questions snapshot
        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => ExamAttemptStatus::Grading->value,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
            'score' => 5,
        ]);

        ExamAttemptQuestion::create([
            'attempt_id' => $attempt->id,
            'question_id' => $mcq->id,
            'question_text' => $mcq->question_text,
            'question_type' => QuestionType::SingleChoice->value,
            'points' => 5,
            'position' => 1,
        ]);

        ExamAttemptQuestion::create([
            'attempt_id' => $attempt->id,
            'question_id' => $essay->id,
            'question_text' => $essay->question_text,
            'question_type' => QuestionType::Essay->value,
            'points' => 10,
            'position' => 2,
        ]);

        $mcqAnswer = ExamAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $mcq->id,
            'is_correct' => true,
            'points_earned' => 5,
        ]);

        $essayAnswer = ExamAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $essay->id,
            'answer_text' => 'My comprehensive essay response.',
            'is_correct' => false,
            'points_earned' => 0,
        ]);

        // Teacher grades the essay answer
        $gradeRes = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $essay->id,
                'awarded_points' => 8,
                'feedback' => 'Good response, missed minor details.',
            ]);

        $gradeRes->assertStatus(200);

        $essayAnswer->refresh();
        $this->assertEquals(8, $essayAnswer->points_earned);
        $this->assertEquals('Good response, missed minor details.', $essayAnswer->feedback);
    }

    public function test_score_publication_workflow_protects_unreleased_grades(): void
    {
        $teacher = $this->makeTeacher();
        $course = $this->createCourse($teacher);

        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'status' => ExamStatus::Published,
        ]);

        $question = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => QuestionType::Essay->value,
            'points' => 20,
        ]);

        $student = $this->createUserWithRole(UserRole::Student, [
            'can_take_exams' => true,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $student->id,
            'status' => ExamAttemptStatus::Submitted->value,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
            'score' => 20,
            'percentage' => 100,
            'grades_published_at' => null,
        ]);

        ExamAttemptQuestion::create([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'question_text' => $question->question_text,
            'question_type' => QuestionType::Essay->value,
            'points' => 20,
            'position' => 1,
        ]);

        ExamAnswer::create([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'is_correct' => true,
            'points_earned' => 20,
        ]);

        // Student views result BEFORE publication via ExamResultResource -> grade hidden
        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => $student);
        $dataBefore = (new ExamResultResource($attempt))->toResponse($req)->getData(true)['data'];

        $this->assertFalse($dataBefore['grades_published']);
        $this->assertArrayNotHasKey('score', $dataBefore);
        $this->assertArrayNotHasKey('percentage', $dataBefore);

        // Teacher publishes grades for the attempt
        $pubRes = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades");

        $pubRes->assertStatus(200);

        // Student views result AFTER publication -> grade visible
        $attempt->refresh();
        $dataAfter = (new ExamResultResource($attempt))->toResponse($req)->getData(true)['data'];

        $this->assertTrue($dataAfter['grades_published']);
        $this->assertEquals(20, $dataAfter['score']);
        $this->assertEquals(100, $dataAfter['percentage']);
    }

    public function test_assistant_role_operational_permissions(): void
    {
        $assistant = $this->makeAssistant();

        // Assistant can list students
        $res = $this->actingAs($assistant, 'sanctum')
            ->getJson('/api/v1/teacher/students');

        $res->assertStatus(200);

        // Assistant can create student
        $createRes = $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Assistant Created Student',
                'phone' => '+201199887766',
                'academic_year' => AcademicYear::Secondary1->value,
            ]);

        $createRes->assertStatus(201);
    }
}
