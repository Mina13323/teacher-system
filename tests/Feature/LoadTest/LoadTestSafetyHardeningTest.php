<?php

namespace Tests\Feature\LoadTest;

use App\Actions\Exam\StartExamAttemptAction;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use App\Services\LoadTest\LoadTestPassword;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ApiTestCase;

/**
 * Password policy and fixture-identity protections of the staging load-test
 * tooling. Everything runs against the test database; nothing is remote.
 */
class LoadTestSafetyHardeningTest extends ApiTestCase
{
    private const STRONG = 'Test-Only#Fixture-Secret-91x';

    private function asStaging(?string $password = self::STRONG): void
    {
        config([
            'app.env' => 'staging',
            'app.url' => 'https://staging.maherelmasry.com',
            'loadtest.required_database' => (string) config('database.connections.'.config('database.default').'.database'),
            'loadtest.password' => $password,
        ]);
    }

    private function seedStatus(int $students = 2): int
    {
        return Artisan::call('loadtest:seed', ['--students' => $students]);
    }

    private function fixtureUserCount(): int
    {
        return User::where('email', 'like', 'loadtest.%')->count();
    }

    // --- password policy --------------------------------------------------

    public function test_seed_refuses_when_password_is_not_configured(): void
    {
        $this->asStaging(null);

        $this->assertSame(1, $this->seedStatus());
        $this->assertStringContainsString('LOADTEST_PASSWORD', Artisan::output());
        $this->assertSame(0, $this->fixtureUserCount());

        $this->asStaging('');
        $this->assertSame(1, $this->seedStatus());
        $this->assertSame(0, $this->fixtureUserCount());
    }

    #[DataProvider('weakPasswords')]
    public function test_seed_refuses_weak_passwords_without_echoing_them(string $weak): void
    {
        $this->asStaging($weak);

        $this->assertSame(1, $this->seedStatus());
        $this->assertStringNotContainsString($weak, Artisan::output());
        $this->assertSame(0, $this->fixtureUserCount());
        $this->assertNotSame([], LoadTestPassword::problems($weak));
    }

    public static function weakPasswords(): array
    {
        return [
            'retired public default' => ['LoadTest#Staging-2026'],
            'retired default, other case' => ['loadtest#staging-2026'],
            'too short' => ['Ab1#xyz'],
            'letters only' => ['abcdefghijklmnopqrstuvwxyz'],
            'no symbol' => ['Abcdefghijklmnop1234'],
            'repeated character' => ['aaaaaaaaaaaaaaaaaaaa'],
        ];
    }

    public function test_a_strong_password_is_accepted_and_never_printed(): void
    {
        $this->asStaging();

        $this->assertSame(0, $this->seedStatus());
        $this->assertStringNotContainsString(self::STRONG, Artisan::output());
        $this->assertSame([], LoadTestPassword::problems(self::STRONG));
        $this->assertSame(2, User::where('email', 'like', 'loadtest.student.%')->count());
    }

    public function test_config_has_no_default_password(): void
    {
        $config = file_get_contents(base_path('config/loadtest.php'));

        $this->assertStringNotContainsString('LoadTest#Staging-2026', $config);
        $this->assertStringContainsString("'password' => env('LOADTEST_PASSWORD'),", $config);
    }

    public function test_clean_does_not_require_the_password(): void
    {
        $this->asStaging();
        $this->assertSame(0, $this->seedStatus());

        $this->asStaging(null);
        $this->artisan('loadtest:clean')->assertSuccessful();
        $this->assertSame(0, $this->fixtureUserCount());
    }

    // --- seeder identity protection ----------------------------------------

    public function test_seed_refuses_to_reset_a_non_fixture_account_with_a_fixture_email(): void
    {
        $this->asStaging();
        $real = $this->createUserWithRole(UserRole::Student, [
            'email' => 'loadtest.student.0001@staging.maherelmasry.com',
            'student_code' => 'REAL-0001',
            'is_active' => false,
        ]);
        $hash = $real->password;

        $this->assertSame(1, $this->seedStatus(3));

        $real->refresh();
        $this->assertSame($hash, $real->password);
        $this->assertFalse((bool) $real->is_active);
        $this->assertSame('REAL-0001', $real->student_code);
        $this->assertNull(Course::where('slug', 'load-test-course')->first());
        $this->assertSame(0, User::where('email', 'loadtest.teacher@staging.maherelmasry.com')->count());
    }

    public function test_seed_refuses_to_take_over_a_course_with_the_fixture_slug(): void
    {
        $this->asStaging();
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['slug' => 'load-test-course', 'title' => 'Someone else']);

        $this->assertSame(1, $this->seedStatus());

        $course->refresh();
        $this->assertSame('Someone else', $course->title);
        $this->assertSame($owner->id, (int) $course->created_by);
    }

    public function test_seed_refuses_when_the_teacher_email_belongs_to_another_role(): void
    {
        $this->asStaging();
        $admin = $this->createUserWithRole(UserRole::Admin, ['email' => 'loadtest.teacher@staging.maherelmasry.com']);

        $this->assertSame(1, $this->seedStatus());

        $this->assertTrue($admin->fresh()->hasRole(UserRole::Admin->value));
        $this->assertSame(0, User::where('email', 'like', 'loadtest.student.%')->count());
    }

    // --- cleaner scope ------------------------------------------------------

    public function test_clean_ignores_look_alike_accounts_that_are_not_fixture_shaped(): void
    {
        $this->asStaging();
        $lookAlike = $this->createUserWithRole(UserRole::Student, [
            'email' => 'loadtest.student.alice@staging.maherelmasry.com',
            'student_code' => 'LT-ALICE',
        ]);
        $this->assertSame(0, $this->seedStatus(2));

        $this->artisan('loadtest:clean')->assertSuccessful();

        $this->assertNotNull(User::find($lookAlike->id));
        $this->assertSame(0, User::where('email', 'like', 'loadtest.student.0%')->count());
    }

    public function test_clean_dry_run_changes_nothing(): void
    {
        $this->asStaging();
        $this->assertSame(0, $this->seedStatus(3));
        $student = User::where('email', 'loadtest.student.0001@staging.maherelmasry.com')->firstOrFail();
        app(StartExamAttemptAction::class)->execute($student, Exam::firstOrFail(), true);

        $tables = ['users', 'courses', 'exams', 'questions', 'options', 'enrollments', 'student_access_periods',
            'exam_attempts', 'exam_attempt_questions', 'exam_attempt_options', 'personal_access_tokens'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);

        $this->artisan('loadtest:clean', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables));
    }

    public function test_reset_attempts_keeps_a_real_students_attempt_on_the_fixture_exam(): void
    {
        $this->asStaging();
        $this->assertSame(0, $this->seedStatus(2));
        $exam = Exam::firstOrFail();
        $fixtureStudent = User::where('email', 'loadtest.student.0001@staging.maherelmasry.com')->firstOrFail();
        app(StartExamAttemptAction::class)->execute($fixtureStudent, $exam, true);

        $real = $this->createUserWithRole(UserRole::Student, ['email' => 'real.student@example.com']);
        Enrollment::create(['student_id' => $real->id, 'course_id' => $exam->course_id, 'status' => 'active', 'enrolled_at' => now()]);
        StudentAccessPeriod::create([
            'student_id' => $real->id, 'status' => 'active', 'starts_at' => now()->subDay(),
            'expires_at' => now()->addMonth(), 'approved_by' => $exam->created_by, 'approved_at' => now(),
        ]);
        app(StartExamAttemptAction::class)->execute($real, $exam, true);

        $this->assertSame(0, Artisan::call('loadtest:seed', ['--students' => 2, '--reset-attempts' => true]));

        $this->assertSame(0, DB::table('exam_attempts')->where('student_id', $fixtureStudent->id)->count());
        $this->assertSame(1, DB::table('exam_attempts')->where('student_id', $real->id)->count());
    }
}
