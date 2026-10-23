<?php

namespace Tests\Feature\LoadTest;

use App\Actions\Exam\StartExamAttemptAction;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Question;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use App\Services\LoadTest\LoadTestFixtureSeeder;
use Tests\Feature\ApiTestCase;

class LoadTestFixtureTest extends ApiTestCase
{
    private function asStaging(): void
    {
        config([
            'app.env' => 'staging',
            'app.url' => 'https://staging.maherelmasry.com',
            'loadtest.required_database' => (string) config('database.connections.'.config('database.default').'.database'),
            // Test-only secret: production/staging have no default password.
            'loadtest.password' => 'Test-Only#Fixture-Secret-91x',
        ]);
    }

    private function seedFixture(int $students = 3): int
    {
        $this->asStaging();

        return $this->artisan('loadtest:seed', ['--students' => $students])->run();
    }

    public function test_safety_guard_rejects_non_staging_environment(): void
    {
        // Test env: APP_ENV=testing, APP_URL/DB do not match staging.
        $this->artisan('loadtest:seed', ['--students' => 2])->assertFailed();
        $this->assertSame(0, User::where('email', 'like', 'loadtest.%')->count());

        $this->artisan('loadtest:clean')->assertFailed();
    }

    public function test_guard_requires_every_condition_not_just_app_env(): void
    {
        $this->asStaging();
        config(['app.url' => 'https://example.com']);
        $this->artisan('loadtest:seed', ['--students' => 2])->assertFailed();

        $this->asStaging();
        config(['loadtest.required_database' => 'u481922752_staging']); // not the connected DB
        $this->artisan('loadtest:seed', ['--students' => 2])->assertFailed();

        $this->assertSame(0, User::where('email', 'like', 'loadtest.%')->count());
    }

    public function test_fixture_creates_deterministic_valid_records(): void
    {
        $this->assertSame(0, $this->seedFixture(3));

        $teacher = User::where('email', 'loadtest.teacher@staging.maherelmasry.com')->firstOrFail();
        $this->assertTrue($teacher->isTeacher());
        $this->assertTrue($teacher->can('exams.create'));

        $course = Course::where('slug', 'load-test-course')->firstOrFail();
        $this->assertSame(CourseStatus::Published, $course->status);

        $exam = Exam::where('course_id', $course->id)->firstOrFail();
        $this->assertSame(ExamStatus::Published, $exam->status);
        $this->assertTrue(now()->between($exam->starts_at, $exam->ends_at));

        $questions = Question::where('exam_id', $exam->id)->get();
        $this->assertCount(20, $questions);
        foreach ($questions as $q) {
            $this->assertTrue($q->hasValidAnswerKey(), 'invalid key on question '.$q->position);
        }
        $this->assertGreaterThan(0, $questions->where('type.value', 'multiple_choice')->count());

        foreach ([1, 2, 3] as $n) {
            $student = User::where('email', "loadtest.student.000{$n}@staging.maherelmasry.com")->firstOrFail();
            $this->assertTrue($student->hasRole(UserRole::Student->value));
            $this->assertTrue($student->isActive());
            $this->assertTrue($student->canTakeExams());
            $this->assertFalse((bool) $student->must_change_password);
            $this->assertTrue($student->hasActiveAccess());
            $this->assertSame(sprintf('LT-%04d', $n), $student->student_code);
            $this->assertTrue(StudentAccessPeriod::active()->where('student_id', $student->id)->exists());
            $this->assertSame(EnrollmentStatus::Active, Enrollment::where('student_id', $student->id)->where('course_id', $course->id)->value('status'));
        }
    }

    public function test_seed_is_idempotent_and_scales_up(): void
    {
        $this->seedFixture(3);
        $this->seedFixture(3);

        $this->assertSame(3, User::where('email', 'like', 'loadtest.student.%')->count());
        $this->assertSame(1, Course::where('slug', 'load-test-course')->count());
        $this->assertSame(1, Exam::count());
        $this->assertSame(20, Question::count());
        $this->assertSame(3, Enrollment::count());
        $this->assertSame(3, StudentAccessPeriod::count());

        $this->seedFixture(5);
        $this->assertSame(5, User::where('email', 'like', 'loadtest.student.%')->count());
        $this->assertSame(5, StudentAccessPeriod::count());
    }

    public function test_fixture_student_can_start_attempt_through_real_flow(): void
    {
        $this->seedFixture(2);
        $student = User::where('email', 'loadtest.student.0001@staging.maherelmasry.com')->firstOrFail();
        $exam = Exam::firstOrFail();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => config('loadtest.password'),
        ])->assertOk();
        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/student/exams')->assertOk();
        $start = $this->withToken($token)
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertCreated();

        $this->assertNotNull($start->json('data.id'));
        $this->assertSame($exam->id, app(StartExamAttemptAction::class)->execute($student, $exam, true)->exam_id);
    }

    public function test_cleanup_removes_only_load_test_records(): void
    {
        $realTeacher = $this->createUserWithRole(UserRole::Teacher);
        $realStudent = $this->createUserWithRole(UserRole::Student, ['email' => 'real.student@example.com']);
        $realCourse = $this->createCourse($realTeacher, ['slug' => 'real-course']);
        Enrollment::create(['student_id' => $realStudent->id, 'course_id' => $realCourse->id, 'status' => 'active', 'enrolled_at' => now()]);
        $realCourse->exams()->create([
            'title' => 'Real exam', 'duration_minutes' => 30, 'pass_percentage' => 50, 'max_attempts' => 1,
            'status' => 'published', 'created_by' => $realTeacher->id,
        ]);

        $this->seedFixture(3);
        // Produce an attempt so cleanup has attempt/snapshot rows to remove.
        $student = User::where('email', 'loadtest.student.0001@staging.maherelmasry.com')->firstOrFail();
        app(StartExamAttemptAction::class)->execute($student, Exam::where('title', config('loadtest.exam_title'))->firstOrFail(), true);

        $this->artisan('loadtest:clean')->assertSuccessful();

        $this->assertSame(0, User::where('email', 'like', 'loadtest.%')->count());
        $this->assertNull(Course::where('slug', 'load-test-course')->first());
        $this->assertSame(0, Question::count());
        $this->assertSame(0, \DB::table('exam_attempts')->count());
        $this->assertSame(0, \DB::table('exam_attempt_questions')->count());
        $this->assertSame(0, StudentAccessPeriod::count());

        // Non-fixture data untouched.
        $this->assertNotNull(User::find($realStudent->id));
        $this->assertNotNull(User::find($realTeacher->id));
        $this->assertNotNull(Course::find($realCourse->id));
        $this->assertSame(1, Exam::count());
        $this->assertSame(1, Enrollment::count());
        $this->assertTrue($realStudent->fresh()->hasRole(UserRole::Student->value));
    }

    public function test_student_identifiers_are_deterministic(): void
    {
        $this->assertSame('loadtest.student.1500@staging.maherelmasry.com', LoadTestFixtureSeeder::studentEmail(1500));
        $this->assertSame('LT-0007', LoadTestFixtureSeeder::studentCode(7));
    }
}
