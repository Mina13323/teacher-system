<?php

namespace App\Services\LoadTest;

use App\Actions\Exam\PublishExamAction;
use App\Enums\AcademicSubject;
use App\Enums\AcademicYear;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\StudentAccessStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Exam;
use App\Models\Option;
use App\Models\Question;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Builds the deterministic load-test fixture through the application's real
 * models, enums and roles. Students are written in bulk (one shared bcrypt
 * hash, chunked inserts) but carry exactly the state CreateStudentAction
 * produces, so the real login / StartExamAttemptAction paths accept them.
 */
class LoadTestFixtureSeeder
{
    public const QUESTION_COUNT = 20;

    /** Marker on fixture access periods so cleanup/reset only touches ours. */
    public const ACCESS_NOTE = 'loadtest-fixture';

    private const CHUNK = 250;

    public function __construct(private readonly LoadTestGuard $guard)
    {
    }

    public static function studentEmail(int $n): string
    {
        return config('loadtest.student_email_prefix').sprintf('%04d', $n).'@'.config('loadtest.email_domain');
    }

    public static function studentCode(int $n): string
    {
        return config('loadtest.student_code_prefix').sprintf('%04d', $n);
    }

    /**
     * @return array{students:int, created:int, teacher_id:int, course_id:int, exam_id:int, questions:int}
     */
    public function seed(int $students, int $windowDays = 7, bool $resetAttempts = false, int $durationMinutes = 60): array
    {
        if ($durationMinutes < 1 || $durationMinutes > 240) {
            throw new \InvalidArgumentException('--duration must be between 1 and 240 minutes.');
        }

        $this->guard->assertStaging();

        $max = (int) config('loadtest.max_students');
        if ($students < 1 || $students > $max) {
            throw new \InvalidArgumentException("--students must be between 1 and {$max}.");
        }

        $this->ensureRolesAndPermissions();
        $hash = Hash::make((string) config('loadtest.password'));

        $teacher = $this->ensureTeacher($hash);
        $course = $this->ensureCourse($teacher);
        $exam = $this->ensureExam($course, $teacher, $windowDays, $durationMinutes);
        $questions = $this->ensureQuestions($exam);

        if ($resetAttempts) {
            app(LoadTestCleaner::class)->deleteAttempts();
        }

        $created = $this->ensureStudents($students, $hash, $teacher, $course, $windowDays);

        // Same publish validation the application enforces, minus the
        // per-student notification fan-out (not meaningful for fixtures).
        app(PublishExamAction::class)->assertValid($exam);
        $exam->status = ExamStatus::Published->value;
        $exam->save();

        return [
            'students' => $students,
            'created' => $created,
            'teacher_id' => $teacher->getKey(),
            'course_id' => $course->getKey(),
            'exam_id' => $exam->getKey(),
            'questions' => $questions,
        ];
    }

    private function ensureRolesAndPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $teacherRole = Role::where('name', UserRole::Teacher->value)->first();
        $studentRole = Role::where('name', UserRole::Student->value)->first();

        // Baseline RBAC (idempotent, no load-test data). Required so the
        // teacher gets the REAL role/permissions rather than a bypass.
        if (! $teacherRole || ! $studentRole || $teacherRole->permissions()->count() === 0) {
            (new RoleSeeder())->run();
            (new PermissionSeeder())->run();
        }
    }

    private function ensureTeacher(string $hash): User
    {
        // `password` is cast 'hashed'; Laravel keeps an already-bcrypt value as-is.
        $teacher = User::updateOrCreate(
            ['email' => config('loadtest.teacher_email')],
            [
                'name' => 'Load Test Teacher',
                'password' => $hash,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $teacher->syncRoles([UserRole::Teacher->value]);

        return $teacher;
    }

    private function ensureCourse(User $teacher): Course
    {
        $course = Course::withTrashed()->firstOrNew(['slug' => config('loadtest.course_slug')]);
        $course->fill([
            'title' => config('loadtest.course_title'),
            'description' => 'Staging-only course used by the load-test fixtures. Safe to delete with loadtest:clean.',
            'status' => CourseStatus::Published->value,
            'created_by' => $teacher->getKey(),
        ]);
        $course->save();
        if ($course->trashed()) {
            $course->restore();
        }

        return $course;
    }

    private function ensureExam(Course $course, User $teacher, int $windowDays, int $durationMinutes = 60): Exam
    {
        $exam = Exam::withTrashed()->firstOrNew([
            'course_id' => $course->getKey(),
            'title' => config('loadtest.exam_title'),
        ]);
        $exam->fill([
            'description' => 'Staging-only load-test exam: 20 questions (16 single choice, 4 multiple choice).',
            // Shorter runs (--duration) let one load-test step include the
            // deadline: every attempt reaches it and is finalized.
            'duration_minutes' => $durationMinutes,
            // Window opens an hour ago so "now" is always inside it, and runs
            // for $windowDays; each re-seed re-centres it on the current time.
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays($windowDays),
            'pass_percentage' => 50,
            'max_attempts' => 1,
            'status' => $exam->exists ? $exam->status->value : ExamStatus::Draft->value,
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'show_result_immediately' => true,
            'expiry_mode' => 'auto_submit',
            'allow_answer_review' => true,
            'created_by' => $teacher->getKey(),
        ]);
        $exam->save();
        if ($exam->trashed()) {
            $exam->restore();
        }

        return $exam;
    }

    /**
     * 16 single-choice (4 options, 1 correct) + 4 multiple-choice (5 options,
     * 2-3 correct). Rebuilt only when the stored set is missing or invalid.
     */
    private function ensureQuestions(Exam $exam): int
    {
        $existing = $exam->questions()->get();
        if ($existing->count() === self::QUESTION_COUNT && $existing->every->hasValidAnswerKey()) {
            return self::QUESTION_COUNT;
        }

        DB::transaction(function () use ($exam, $existing) {
            if ($existing->isNotEmpty()) {
                Question::whereIn('id', $existing->pluck('id'))->delete(); // options cascade
            }

            for ($i = 1; $i <= self::QUESTION_COUNT; $i++) {
                $multiple = $i % 5 === 0; // Q5, Q10, Q15, Q20
                $question = Question::create([
                    'exam_id' => $exam->getKey(),
                    'question_text' => sprintf('Load test question %02d: choose the correct answer(s).', $i),
                    'type' => ($multiple ? QuestionType::MultipleChoice : QuestionType::SingleChoice)->value,
                    'points' => $multiple ? 2 : 1,
                    'position' => $i,
                    'explanation_enabled' => false,
                    'explanation_required' => false,
                ]);

                $optionCount = $multiple ? 5 : 4;
                $correct = $multiple
                    ? ($i % 10 === 0 ? [1, 3, 4] : [2, 4])
                    : [($i % 4) + 1];

                for ($p = 1; $p <= $optionCount; $p++) {
                    Option::create([
                        'question_id' => $question->getKey(),
                        'option_text' => sprintf('Q%02d option %s', $i, chr(64 + $p)),
                        'is_correct' => in_array($p, $correct, true),
                        'position' => $p,
                    ]);
                }
            }
        });

        return self::QUESTION_COUNT;
    }

    /**
     * @return int number of newly created student users
     */
    private function ensureStudents(int $count, string $hash, User $teacher, Course $course, int $windowDays): int
    {
        $studentRoleId = Role::where('name', UserRole::Student->value)->value('id');
        $rolesTable = config('permission.table_names.model_has_roles');
        $now = now();
        $accessStart = $now->copy()->subDay();
        // Access always outlives the exam window so a re-run is never "expired".
        $accessEnd = $now->copy()->addDays(max($windowDays, 1) + 30);
        $created = 0;

        foreach (array_chunk(range(1, $count), self::CHUNK) as $numbers) {
            DB::transaction(function () use (
                $numbers, $hash, $teacher, $course, $studentRoleId, $rolesTable, $now, $accessStart, $accessEnd, &$created
            ) {
                $emails = array_map(fn ($n) => self::studentEmail($n), $numbers);
                $existing = User::whereIn('email', $emails)->pluck('id', 'email')->all();

                $newRows = [];
                foreach ($numbers as $n) {
                    $email = self::studentEmail($n);
                    if (isset($existing[$email])) {
                        continue;
                    }
                    $newRows[] = [
                        'name' => sprintf('Load Test Student %04d', $n),
                        'email' => $email,
                        'student_code' => self::studentCode($n),
                        'password' => $hash,
                        'email_verified_at' => $now,
                        'is_active' => true,
                        'must_change_password' => false,
                        'can_access_lessons' => true,
                        'can_take_exams' => true,
                        'can_join_competitions' => true,
                        'academic_year' => AcademicYear::Secondary1->value,
                        'academic_subject' => AcademicSubject::General->value,
                        'created_by' => $teacher->getKey(),
                        'profile_completed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($newRows !== []) {
                    DB::table('users')->insert($newRows);
                    $created += count($newRows);
                }

                // Reset existing fixture students to the canonical fixture state.
                if ($existing !== []) {
                    DB::table('users')->whereIn('id', array_values($existing))->update([
                        'password' => $hash,
                        'is_active' => true,
                        'must_change_password' => false,
                        'can_access_lessons' => true,
                        'can_take_exams' => true,
                        'can_join_competitions' => true,
                        'updated_at' => $now,
                    ]);
                }

                $ids = User::whereIn('email', $emails)->pluck('id')->all();

                // Student role (Spatie pivot) for every fixture student lacking it.
                $hasRole = DB::table($rolesTable)
                    ->where('role_id', $studentRoleId)
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $ids)
                    ->pluck('model_id')->all();
                $roleRows = [];
                foreach (array_diff($ids, $hasRole) as $id) {
                    $roleRows[] = ['role_id' => $studentRoleId, 'model_type' => User::class, 'model_id' => $id];
                }
                if ($roleRows !== []) {
                    DB::table($rolesTable)->insert($roleRows);
                }

                // Access periods: one active period per student, tagged for cleanup.
                $hasPeriod = DB::table('student_access_periods')
                    ->whereIn('student_id', $ids)
                    ->where('notes', self::ACCESS_NOTE)
                    ->pluck('student_id')->all();
                DB::table('student_access_periods')
                    ->whereIn('student_id', $ids)
                    ->where('notes', self::ACCESS_NOTE)
                    ->update([
                        'status' => StudentAccessStatus::Active->value,
                        'starts_at' => $accessStart,
                        'expires_at' => $accessEnd,
                        'updated_at' => $now,
                    ]);
                $periodRows = [];
                foreach (array_diff($ids, $hasPeriod) as $id) {
                    $periodRows[] = [
                        'student_id' => $id,
                        'status' => StudentAccessStatus::Active->value,
                        'starts_at' => $accessStart,
                        'expires_at' => $accessEnd,
                        'amount' => null,
                        'notes' => self::ACCESS_NOTE,
                        'approved_by' => $teacher->getKey(),
                        'approved_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($periodRows !== []) {
                    DB::table('student_access_periods')->insert($periodRows);
                }

                // Enrollments (unique student_id+course_id) -> active.
                DB::table('enrollments')->upsert(
                    array_map(fn ($id) => [
                        'student_id' => $id,
                        'course_id' => $course->getKey(),
                        'status' => EnrollmentStatus::Active->value,
                        'enrolled_at' => $now,
                        'completed_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $ids),
                    ['student_id', 'course_id'],
                    ['status', 'completed_at', 'updated_at']
                );
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $created;
    }
}
