<?php

namespace Tests\Feature\Performance;

use App\Enums\UserRole;
use App\Http\Middleware\LogContextMiddleware;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Every API request logs the numbers needed to watch database pressure in
 * production: statements, writes, transactions and the longest one, whether a
 * connection was opened, and the response size.
 */
class RequestMetricsLoggingTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_a_write_request_logs_its_database_and_size_metrics(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, ['duration_minutes' => 30]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $attemptId = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->json('data.id');

        Log::spy();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/submit")
            ->assertOk();

        Log::shouldHaveReceived('info')->with('api.request.completed', Mockery::on(function (array $metrics) use ($response, $attemptId) {
            return $metrics['attempt_id'] == $attemptId
                && $metrics['db_queries'] > 0
                && $metrics['db_writes'] >= 2
                && $metrics['db_transactions'] === 1
                && $metrics['db_longest_transaction_ms'] >= 0
                && $metrics['db_connected'] === true
                && $metrics['response_bytes'] === strlen($response->getContent())
                && $metrics['route_uri'] === 'api/v1/student/attempts/{attempt}/submit'
                && $metrics['area'] === 'student'
                && $metrics['traffic'] === 'exam'
                && ! array_key_exists('password', $metrics)
                && ! array_key_exists('answer_text', $metrics);
        }))->once();
    }

    public function test_paths_are_classified_by_area_and_traffic(): void
    {
        $cases = [
            'api/v1/student/attempts/5/answers' => ['student', 'exam'],
            'api/v1/student/attempts/5/heartbeat' => ['student', 'exam'],
            'api/v1/student/attempts/5/integrity-events' => ['student', 'exam'],
            'api/v1/student/exams/9/start' => ['student', 'exam'],
            'api/v1/student/exams/9' => ['student', 'lms'],
            'api/v1/student/dashboard' => ['student', 'lms'],
            'api/v1/teacher/exams/9/attempts' => ['teacher', 'lms'],
            'api/v1/admin/students' => ['admin', 'lms'],
            'api/v1/auth/me' => ['auth', 'auth'],
            'api/v1/time' => ['clock', 'clock'],
            'api/v1/courses' => ['shared', 'lms'],
        ];

        foreach ($cases as $path => [$area, $traffic]) {
            $this->assertSame($area, LogContextMiddleware::area($path), $path);
            $this->assertSame($traffic, LogContextMiddleware::traffic($path), $path);
        }
    }

    public function test_the_summary_command_counts_connections_per_second_and_per_route(): void
    {
        $file = storage_path('framework/testing/metrics-'.uniqid().'.log');
        @mkdir(dirname($file), 0777, true);
        $line = fn (string $time, array $m) => "[{$time}] testing.INFO: api.request.completed ".json_encode($m).PHP_EOL;
        $answer = ['method' => 'POST', 'route_uri' => 'api/v1/student/attempts/{attempt}/answers', 'traffic' => 'exam', 'area' => 'student', 'db_connected' => true, 'db_queries' => 11, 'db_writes' => 3, 'duration_ms' => 40, 'status' => 200];
        $time = ['method' => 'GET', 'route_uri' => 'api/v1/time', 'traffic' => 'clock', 'area' => 'clock', 'db_connected' => false, 'db_queries' => 0, 'duration_ms' => 2, 'status' => 200];
        file_put_contents($file, implode('', [
            $line('2026-10-09 10:00:00', $answer),
            $line('2026-10-09 10:00:00', $answer),
            $line('2026-10-09 10:00:00', $time),
            $line('2026-10-09 10:00:01', $answer),
            '[2026-10-09 10:00:01] testing.ERROR: something else {"x":1}'.PHP_EOL,
            $line('2026-10-09 10:00:05', $time),
        ]));

        try {
            $this->assertSame(0, Artisan::call('metrics:requests', ['--file' => [$file]]));
            $out = Artisan::output();
            $this->assertStringContainsString('Range: 2026-10-09 10:00:00 .. 2026-10-09 10:00:05 (6 s)', $out);
            $this->assertMatchesRegularExpression('/Requests\s*\|\s*5\s*\|/', $out);
            $this->assertMatchesRegularExpression('/opened a DB connection\s*\|\s*3\s*\|/', $out);
            $this->assertMatchesRegularExpression('/peak second\s*\|\s*2\s*\|/', $out);
            $this->assertMatchesRegularExpression('#POST api/v1/student/attempts/\{attempt\}/answers\s*\|\s*3\s*\|\s*3\s*\|\s*11\s*\|#', $out);
        } finally {
            @unlink($file);
        }
    }
}
