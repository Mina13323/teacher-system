<?php

namespace Tests\Feature\Stability;

use App\Enums\UserRole;
use App\Models\ExamAttempt;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

class ServerTimeEndpointTest extends ApiTestCase
{
    use InteractsWithExams;

    public function test_it_returns_the_server_time_without_touching_the_database(): void
    {
        // The production cache and session stores are database-backed; the
        // endpoint must not open a connection through either of them.
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->useDatabaseBackedRateLimiter();
        Carbon::setTestNow('2026-10-08 12:00:00.250');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $student = $this->createUserWithRole(UserRole::Student);
        $token = $student->createToken('t')->plainTextToken;
        $queries = 0;

        // Called anonymously and with the bearer token the exam client sends.
        foreach ([[], ['Authorization' => 'Bearer '.$token]] as $headers) {
            $this->getJson('/api/v1/time', $headers)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.server_time', '2026-10-08T12:00:00.250000Z')
                ->assertJsonPath('data.server_time_ms', 1791460800250);
        }

        $this->assertSame(0, $queries);
        Carbon::setTestNow();
    }

    public function test_throttled_routes_do_use_the_database_cache_in_this_setup(): void
    {
        // Guards the test above: with this setup a normal API route DOES hit
        // the database through the rate limiter, so zero queries on /time
        // proves the throttle is really skipped there.
        $this->useDatabaseBackedRateLimiter();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        // An unauthenticated public route: auth would reject /auth/me before
        // the throttle middleware runs.
        $this->getJson('/api/v1/public/certificates/UNKNOWN');

        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'cache')));
    }

    public function test_it_reveals_nothing_but_the_time(): void
    {
        $data = $this->getJson('/api/v1/time')->assertOk()->json('data');

        $this->assertSame(['server_time', 'server_time_ms'], array_keys($data));
    }

    public function test_the_heartbeat_reports_the_deadline_and_server_time(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, ['duration_minutes' => 30, 'max_attempts' => 5]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $attemptId = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->json('data.id');

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attemptId}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $attempt = ExamAttempt::findOrFail($attemptId);
        $this->assertSame($attempt->expires_at->toISOString(), $res->json('data.expires_at'));
        $this->assertIsInt($res->json('data.server_time_ms'));
        $this->assertNotNull($attempt->last_heartbeat_at, 'The heartbeat still records liveness.');
    }

    /**
     * The rate limiter captured the array cache store at boot; rebuild it on
     * the database store, as production runs with CACHE_STORE=database.
     */
    private function useDatabaseBackedRateLimiter(): void
    {
        $current = $this->app->make(RateLimiter::class);
        $limiters = (fn () => $this->limiters)->call($current);
        $replacement = new RateLimiter($this->app['cache']->store('database'));
        foreach ($limiters as $name => $callback) {
            $replacement->for($name, $callback);
        }
        $this->app->instance(RateLimiter::class, $replacement);
    }
}
