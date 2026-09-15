<?php

namespace Tests\Feature\Authorization;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ApiTestCase;

/**
 * Teacher == Assistant operational parity.
 *
 * Proves the acceptance criterion as a single invariant rather than one test
 * per endpoint: for every major operational LMS capability, Teacher and
 * Assistant receive the same status, Admin keeps its override, and Student is
 * refused.
 *
 * If a future Teacher endpoint forgets the Assistant, its row fails here.
 */
class StaffParityMatrixTest extends ApiTestCase
{
    private User $teacher;

    private User $assistant;

    private User $admin;

    private User $studentUser;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->assistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $this->teacher->id]);
        $this->admin = $this->createUserWithRole(UserRole::Admin);

        // created_by links the student to this teacher, which is what
        // StudentPolicy::managesStudent requires for the per-student endpoints
        // (view / analytics) to resolve for the teacher as well as the assistant.
        $this->studentUser = $this->createUserWithRole(UserRole::Student, [
            'created_by' => $this->teacher->id,
        ]);

        $course = $this->createCourse($this->teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);

        $lesson = $this->createLesson($unit);

        $exam = Exam::factory()->create([
            'course_id' => $course->id,
            'created_by' => $this->teacher->id,
            'status' => ExamStatus::Published,
            'duration_minutes' => 30,
        ]);

        $essay = Question::factory()->create([
            'exam_id' => $exam->id,
            'type' => QuestionType::Essay->value,
            'points' => 20,
            'position' => 1,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'exam_id' => $exam->id,
            'student_id' => $this->studentUser->id,
            'attempt_number' => 1,
            'status' => ExamAttemptStatus::Submitted,
            'pass_percentage' => 50,
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(20),
            'submitted_at' => now(),
        ]);

        $this->ids = [
            'course' => $course->id,
            'unit' => $unit->id,
            'lesson' => $lesson->id,
            'exam' => $exam->id,
            'question' => $essay->id,
            'attempt' => $attempt->id,
            'student' => $this->studentUser->id,
        ];
    }

    private function createLesson($unit)
    {
        return \App\Models\Lesson::factory()->create([
            'unit_id' => $unit->id,
            'position' => 1,
        ]);
    }

    private function path(string $template): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => $this->ids[$m[1]], $template);
    }

    /**
     * [capability, method, path, payload, expected status for teacher/assistant/admin]
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4: int}>
     */
    public static function capabilityMatrix(): array
    {
        return [
            'dashboard' => ['dashboard', 'get', '/api/v1/teacher/dashboard', [], 200],

            'students.list' => ['students.list', 'get', '/api/v1/teacher/students', [], 200],
            'students.create' => ['students.create', 'post', '/api/v1/teacher/students', [
                'name' => 'Matrix Student',
                'email' => 'matrix.student@example.com',
                'password' => 'password123',
            ], 201],
            'students.show' => ['students.show', 'get', '/api/v1/teacher/students/{student}', [], 200],

            'courses.list' => ['courses.list', 'get', '/api/v1/teacher/courses', [], 200],
            'courses.show' => ['courses.show', 'get', '/api/v1/teacher/courses/{course}', [], 200],
            'courses.students' => ['courses.students', 'get', '/api/v1/teacher/courses/{course}/students', [], 200],

            'units.list' => ['units.list', 'get', '/api/v1/teacher/courses/{course}/units', [], 200],
            'units.create' => ['units.create', 'post', '/api/v1/teacher/courses/{course}/units', ['title' => 'Matrix Unit'], 201],
            'units.show' => ['units.show', 'get', '/api/v1/teacher/units/{unit}', [], 200],

            'lessons.list' => ['lessons.list', 'get', '/api/v1/teacher/units/{unit}/lessons', [], 200],
            'lessons.create' => ['lessons.create', 'post', '/api/v1/teacher/units/{unit}/lessons', ['title' => 'Matrix Lesson'], 201],
            'lessons.show' => ['lessons.show', 'get', '/api/v1/teacher/lessons/{lesson}', [], 200],

            'videos.list' => ['videos.list', 'get', '/api/v1/teacher/lessons/{lesson}/videos', [], 200],

            'exams.list' => ['exams.list', 'get', '/api/v1/teacher/courses/{course}/exams', [], 200],
            'exams.show' => ['exams.show', 'get', '/api/v1/teacher/exams/{exam}', [], 200],
            'exams.attempts' => ['exams.attempts', 'get', '/api/v1/teacher/exams/{exam}/attempts', [], 200],

            'questions.list' => ['questions.list', 'get', '/api/v1/teacher/exams/{exam}/questions', [], 200],
            'questions.create' => ['questions.create', 'post', '/api/v1/teacher/exams/{exam}/questions', [
                'question_text' => 'Matrix question?',
                'type' => 'essay',
                'points' => 5,
            ], 201],
            'questions.show' => ['questions.show', 'get', '/api/v1/teacher/questions/{question}', [], 200],
            'options.list' => ['options.list', 'get', '/api/v1/teacher/questions/{question}/options', [], 200],

            'attempts.staffView' => ['attempts.staffView', 'get', '/api/v1/teacher/attempts/{attempt}', [], 200],

            'integrity.examSettings' => ['integrity.examSettings', 'get', '/api/v1/teacher/exams/{exam}/integrity', [], 200],
            'integrity.attempt' => ['integrity.attempt', 'get', '/api/v1/teacher/attempts/{attempt}/integrity', [], 200],
            'integrity.events' => ['integrity.events', 'get', '/api/v1/teacher/attempts/{attempt}/integrity-events', [], 200],

            'analytics.overview' => ['analytics.overview', 'get', '/api/v1/teacher/analytics/overview', [], 200],
            'analytics.course' => ['analytics.course', 'get', '/api/v1/teacher/analytics/courses/{course}', [], 200],
            'analytics.student' => ['analytics.student', 'get', '/api/v1/teacher/analytics/students/{student}', [], 200],

            'competitions.list' => ['competitions.list', 'get', '/api/v1/teacher/competitions', [], 200],
        ];
    }

    /**
     * Teacher and Assistant must receive an identical status for every
     * capability, and Admin keeps its override.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('capabilityMatrix')]
    public function test_teacher_assistant_and_admin_reach_every_operational_capability(
        string $capability,
        string $method,
        string $path,
        array $payload,
        int $expected,
    ): void {
        $url = $this->path($path);

        foreach ([
            'teacher' => $this->teacher,
            'assistant' => $this->assistant,
            'admin' => $this->admin,
        ] as $role => $user) {
            // Each role creates its own account, so emails must not collide.
            $rolePayload = $payload;
            if (isset($rolePayload['email'])) {
                $rolePayload['email'] = $role.'.'.$rolePayload['email'];
            }

            $response = $this->actingAs($user, 'sanctum')->json($method, $url, $rolePayload);

            $this->assertSame(
                $expected,
                $response->status(),
                "[{$capability}] role `{$role}` expected {$expected} but got {$response->status()}: {$response->getContent()}"
            );
        }
    }

    /**
     * A student may never reach a staff endpoint. The role middleware on the
     * teacher group is what stops this, before any policy runs.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('capabilityMatrix')]
    public function test_student_is_refused_every_staff_capability(
        string $capability,
        string $method,
        string $path,
        array $payload,
        int $expected,
    ): void {
        $response = $this->actingAs($this->studentUser, 'sanctum')
            ->json($method, $this->path($path), $payload);

        $this->assertSame(
            403,
            $response->status(),
            "[{$capability}] a student must never reach this staff endpoint, got {$response->status()}"
        );
    }

    // ---------------------------------------------------------------------
    // Mutating capabilities that cannot share a fixture across four roles.
    // ---------------------------------------------------------------------

    public function test_assistant_can_grade_an_essay_exactly_as_a_teacher_can(): void
    {
        foreach ([$this->teacher, $this->assistant] as $staff) {
            $attempt = $this->freshSubmittedEssayAttempt();

            $this->actingAs($staff, 'sanctum')
                ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                    'question_id' => $this->ids['question'],
                    'awarded_points' => 15,
                    'feedback' => 'Good answer.',
                ])
                ->assertStatus(200)
                ->assertJsonPath('success', true);
        }
    }

    public function test_assistant_can_publish_grades_exactly_as_a_teacher_can(): void
    {
        foreach ([$this->teacher, $this->assistant] as $staff) {
            $attempt = $this->freshSubmittedEssayAttempt();

            $this->actingAs($staff, 'sanctum')
                ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
                ->assertStatus(200);
        }
    }

    /**
     * A student cannot grade or publish — the self-grading hole must stay
     * closed even with the role middleware in place.
     */
    public function test_student_cannot_grade_or_publish(): void
    {
        $attempt = $this->freshSubmittedEssayAttempt();

        $this->actingAs($this->studentUser, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/grade-essay", [
                'question_id' => $this->ids['question'],
                'awarded_points' => 20,
            ])
            ->assertStatus(403);

        $this->actingAs($this->studentUser, 'sanctum')
            ->postJson("/api/v1/teacher/attempts/{$attempt->id}/publish-grades")
            ->assertStatus(403);
    }

    /**
     * Each grading test needs an ungraded attempt of its own, because grading
     * mutates the attempt.
     */
    private function freshSubmittedEssayAttempt(): ExamAttempt
    {
        $student = $this->createUserWithRole(UserRole::Student);

        return ExamAttempt::factory()->create([
            'exam_id' => $this->ids['exam'],
            'student_id' => $student->id,
            'attempt_number' => 1,
            'status' => ExamAttemptStatus::Submitted,
            'pass_percentage' => 50,
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(20),
            'submitted_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // Declared Admin/Teacher-only boundary.
    // ---------------------------------------------------------------------

    /**
     * Staff identity administration is the one capability deliberately withheld
     * from the Assistant: minting a new staff account or resetting a staff
     * member's password is account administration, not LMS work.
     */
    public function test_assistant_cannot_administer_staff_identities(): void
    {
        $this->actingAs($this->assistant, 'sanctum')
            ->getJson('/api/v1/teacher/assistants')
            ->assertStatus(403);

        $this->actingAs($this->assistant, 'sanctum')
            ->postJson('/api/v1/teacher/assistants', [
                'name' => 'Rogue Assistant',
                'email' => 'rogue@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertStatus(403);

        // The Teacher can, which proves the 403 is the permission check and not
        // the group middleware.
        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/teacher/assistants')
            ->assertStatus(200);
    }
}
