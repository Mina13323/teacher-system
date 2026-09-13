<?php

namespace Tests\Feature\Student;

use App\Actions\Auth\CreateStudentAction;
use App\Enums\AcademicYear;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\StudentAccessStatus;
use App\Enums\StudentCapabilityPreset;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\StudentAccessPeriod;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\StudentRenewalDueNotification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class StudentCapabilitiesAndRenewalTest extends ApiTestCase
{
    use InteractsWithCompetitions;
    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    public function test_teacher_can_create_student_with_academic_year_and_capabilities(): void
    {
        $teacher = $this->makeTeacher();

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Kareem Ahmed',
                'phone' => '+201012345678',
                'academic_year' => AcademicYear::Secondary2->value,
                'capability_preset' => StudentCapabilityPreset::LessonsOnly->value,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Kareem Ahmed')
            ->assertJsonPath('data.academic_year', AcademicYear::Secondary2->value)
            ->assertJsonPath('data.can_access_lessons', true)
            ->assertJsonPath('data.can_take_exams', false)
            ->assertJsonPath('data.can_join_competitions', false)
            ->assertJsonPath('data.access_status', StudentAccessStatus::Active->value)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'student_code',
                    'academic_year',
                    'access_status',
                ],
                'credentials' => [
                    'student_code',
                    'login',
                    'temporary_password',
                ],
            ]);

        $student = User::where('name', 'Kareem Ahmed')->firstOrFail();
        $this->assertNotNull($student->student_code);
        $this->assertStringStartsWith('ELM-', $student->student_code);
        $this->assertEquals(AcademicYear::Secondary2, $student->academic_year);
        $this->assertTrue($student->can_access_lessons);
        $this->assertFalse($student->can_take_exams);
        $this->assertFalse($student->can_join_competitions);

        // Verify initial access period
        $latestPeriod = $student->latestAccessPeriod;
        $this->assertNotNull($latestPeriod);
        $this->assertEquals(StudentAccessStatus::Active, $latestPeriod->status);
    }

    public function test_student_can_login_with_student_code_or_email(): void
    {
        $teacher = $this->makeTeacher();

        $createResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Sara Mahmoud',
                'academic_year' => AcademicYear::Secondary1->value,
            ])->assertStatus(201);

        $studentCode = $createResponse->json('credentials.student_code');
        $email = $createResponse->json('credentials.login');
        $tempPassword = $createResponse->json('credentials.temporary_password');

        // 1. Authenticate with student code
        $loginByCode = $this->postJson('/api/v1/auth/login', [
            'login' => $studentCode,
            'password' => $tempPassword,
        ])->assertStatus(200);

        $this->assertEquals($studentCode, $loginByCode->json('data.user.student_code'));

        // 2. Authenticate with email
        $loginByEmail = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $tempPassword,
        ])->assertStatus(200);

        $this->assertEquals($studentCode, $loginByEmail->json('data.user.student_code'));
    }

    public function test_suspended_student_cannot_log_in(): void
    {
        $teacher = $this->makeTeacher();

        $createResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Omar Hany',
                'academic_year' => AcademicYear::Secondary3->value,
            ])->assertStatus(201);

        $student = User::where('name', 'Omar Hany')->firstOrFail();
        $studentCode = $createResponse->json('credentials.student_code');
        $password = $createResponse->json('credentials.temporary_password');

        // Suspend the student
        $student->latestAccessPeriod()->update([
            'status' => StudentAccessStatus::Suspended,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => $studentCode,
            'password' => $password,
        ])->assertStatus(403);
    }

    public function test_teacher_can_renew_student_access_keep_active(): void
    {
        $teacher = $this->makeTeacher();

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Laila Nour',
                'academic_year' => AcademicYear::Secondary2->value,
            ])->assertStatus(201);

        $student = User::where('name', 'Laila Nour')->firstOrFail();

        // Expire the access period
        $student->latestAccessPeriod()->update([
            'status' => StudentAccessStatus::Due,
            'expires_at' => now()->subDay(),
        ]);

        $renewResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/renew", [
                'decision' => 'keep_active',
                'notes' => 'Monthly subscription paid cash.',
            ]);

        $renewResponse->assertStatus(200)
            ->assertJsonPath('data.access_status', StudentAccessStatus::Active->value);

        $student->refresh();
        $this->assertTrue($student->hasActiveAccess());
        $this->assertEquals(StudentAccessStatus::Active->value, $student->accessStatus());
    }

    public function test_teacher_can_suspend_student_and_existing_tokens_are_revoked(): void
    {
        $teacher = $this->makeTeacher();

        $createResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Hossam Tarek',
                'academic_year' => AcademicYear::Secondary1->value,
            ])->assertStatus(201);

        $student = User::where('name', 'Hossam Tarek')->firstOrFail();
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'login' => $createResponse->json('credentials.student_code'),
            'password' => $createResponse->json('credentials.temporary_password'),
        ])->assertStatus(200);

        $studentToken = $loginResponse->json('data.token');

        // Teacher suspends the student
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/renew", [
                'decision' => 'suspend',
                'notes' => 'Non-payment for 2 months.',
            ])->assertStatus(200)
            ->assertJsonPath('data.access_status', StudentAccessStatus::Suspended->value);

        // Personal access tokens must be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $student->id,
        ]);

        // Student's token is revoked and cannot be used
        auth()->forgetGuards();
        $this->withToken($studentToken)
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(401);
    }

    public function test_teacher_can_reset_student_credentials(): void
    {
        $teacher = $this->makeTeacher();

        $createResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Nourhan Fathy',
                'academic_year' => AcademicYear::Secondary2->value,
            ])->assertStatus(201);

        $student = User::where('name', 'Nourhan Fathy')->firstOrFail();
        $oldPassword = $createResponse->json('credentials.temporary_password');

        // Reset credentials
        $resetResponse = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'student' => ['id', 'student_code'],
                    'credentials' => ['student_code', 'login', 'temporary_password'],
                ],
            ]);

        $newPassword = $resetResponse->json('data.credentials.temporary_password');
        $this->assertNotEquals($oldPassword, $newPassword);

        // Old password no longer authenticates
        $this->postJson('/api/v1/auth/login', [
            'login' => $student->student_code,
            'password' => $oldPassword,
        ])->assertStatus(401);

        // New password authenticates
        $this->postJson('/api/v1/auth/login', [
            'login' => $student->student_code,
            'password' => $newPassword,
        ])->assertStatus(200);
    }

    public function test_lesson_access_blocked_when_capability_is_false(): void
    {
        $teacher = $this->makeTeacher();

        // Create course, unit, lesson
        $course = Course::factory()->create([
            'created_by' => $teacher->id,
            'status' => CourseStatus::Published,
        ]);
        $unit = Unit::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'unit_id' => $unit->id,
            'is_published' => true,
        ]);

        // Student with can_access_lessons = false
        $action = app(CreateStudentAction::class);
        $student = $action->execute($teacher, [
            'name' => 'Samir Adel',
            'academic_year' => AcademicYear::Secondary1->value,
            'can_access_lessons' => false,
            'can_take_exams' => true,
            'can_join_competitions' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/lessons/{$lesson->id}/videos")
            ->assertStatus(403);
    }

    public function test_exam_access_blocked_when_capability_is_false(): void
    {
        $teacher = $this->makeTeacher();

        $course = Course::factory()->create([
            'created_by' => $teacher->id,
            'status' => CourseStatus::Published,
        ]);
        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'status' => ExamStatus::Published,
        ]);

        // Student with can_take_exams = false
        $action = app(CreateStudentAction::class);
        $student = $action->execute($teacher, [
            'name' => 'Mona Youssef',
            'academic_year' => AcademicYear::Secondary2->value,
            'can_access_lessons' => true,
            'can_take_exams' => false,
            'can_join_competitions' => true,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start")
            ->assertStatus(403);
    }

    public function test_scheduled_renewal_check_is_idempotent_and_creates_notifications(): void
    {
        $teacher = $this->makeTeacher();

        $action = app(CreateStudentAction::class);
        $student = $action->execute($teacher, [
            'name' => 'Ramy Ezzat',
            'academic_year' => AcademicYear::Secondary3->value,
        ]);

        // Move access period expiration into the past
        $period = $student->latestAccessPeriod;
        $period->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        // Run check command
        $this->artisan('students:check-renewals')
            ->assertSuccessful();

        $period->refresh();
        $this->assertEquals(StudentAccessStatus::Due, $period->status);

        // Verify teacher received notification
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $teacher->id,
            'type' => StudentRenewalDueNotification::class,
        ]);

        $notificationCount = $teacher->unreadNotifications()->count();
        $this->assertEquals(1, $notificationCount);

        // Running it again should NOT produce a duplicate notification
        $this->artisan('students:check-renewals')
            ->assertSuccessful();

        $this->assertEquals(1, $teacher->unreadNotifications()->count());
    }

    public function test_student_history_preserved_after_suspension(): void
    {
        $teacher = $this->makeTeacher();

        $course = Course::factory()->create([
            'created_by' => $teacher->id,
            'status' => CourseStatus::Published,
        ]);

        $action = app(CreateStudentAction::class);
        $student = $action->execute($teacher, [
            'name' => 'Mariam Zaki',
            'academic_year' => AcademicYear::Secondary1->value,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        // Teacher suspends student
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/renew", [
                'decision' => 'suspend',
            ])->assertStatus(200);

        // Enrollment record is still intact
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => EnrollmentStatus::Active->value,
        ]);
    }

    public function test_competition_access_blocked_when_capability_is_false(): void
    {
        $teacher = $this->makeTeacher();

        $course = Course::factory()->create([
            'created_by' => $teacher->id,
            'status' => CourseStatus::Published,
        ]);
        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'status' => ExamStatus::Published,
        ]);
        $competition = $this->makeActiveCompetition($teacher, $exam);

        $action = app(CreateStudentAction::class);
        $student = $action->execute($teacher, [
            'name' => 'Fady Maged',
            'academic_year' => AcademicYear::Secondary1->value,
            'can_access_lessons' => true,
            'can_take_exams' => true,
            'can_join_competitions' => false,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/competitions/{$competition->id}/join")
            ->assertStatus(403);
    }

    public function test_presets_assign_expected_capabilities(): void
    {
        $teacher = $this->makeTeacher();
        $action = app(CreateStudentAction::class);

        // NONE
        $none = $action->execute($teacher, [
            'name' => 'Student None',
            'capability_preset' => StudentCapabilityPreset::None->value,
        ]);
        $this->assertFalse($none->can_access_lessons);
        $this->assertFalse($none->can_take_exams);
        $this->assertFalse($none->can_join_competitions);

        // ALL
        $all = $action->execute($teacher, [
            'name' => 'Student All',
            'capability_preset' => StudentCapabilityPreset::All->value,
        ]);
        $this->assertTrue($all->can_access_lessons);
        $this->assertTrue($all->can_take_exams);
        $this->assertTrue($all->can_join_competitions);

        // EXAMS_ONLY
        $examsOnly = $action->execute($teacher, [
            'name' => 'Student Exams Only',
            'capability_preset' => StudentCapabilityPreset::ExamsOnly->value,
        ]);
        $this->assertFalse($examsOnly->can_access_lessons);
        $this->assertTrue($examsOnly->can_take_exams);
        $this->assertFalse($examsOnly->can_join_competitions);

        // COMPETITIONS_ONLY
        $compOnly = $action->execute($teacher, [
            'name' => 'Student Comp Only',
            'capability_preset' => StudentCapabilityPreset::CompetitionsOnly->value,
        ]);
        $this->assertFalse($compOnly->can_access_lessons);
        $this->assertFalse($compOnly->can_take_exams);
        $this->assertTrue($compOnly->can_join_competitions);
    }

    public function test_student_cannot_access_staff_renewal_or_credential_reset_endpoints(): void
    {
        $teacher = $this->makeTeacher();
        $action = app(CreateStudentAction::class);
        $studentA = $action->execute($teacher, ['name' => 'Student A']);
        $studentB = $action->execute($teacher, ['name' => 'Student B']);

        // Student A tries to renew Student B
        $this->actingAs($studentA, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$studentB->id}/renew", [
                'decision' => 'keep_active',
            ])->assertStatus(403);

        // Student A tries to reset credentials of Student B
        $this->actingAs($studentA, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$studentB->id}/reset-credentials")
            ->assertStatus(403);
    }

    public function test_temporary_password_is_hashed_and_not_stored_plaintext(): void
    {
        $teacher = $this->makeTeacher();

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Secured Student',
                'academic_year' => AcademicYear::Secondary1->value,
            ])->assertStatus(201);

        $tempPassword = $response->json('credentials.temporary_password');
        $student = User::where('name', 'Secured Student')->firstOrFail();

        $this->assertNotEquals($tempPassword, $student->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($tempPassword, $student->password));
    }
}
