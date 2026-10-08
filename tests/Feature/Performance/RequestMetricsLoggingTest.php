<?php

namespace Tests\Feature\Performance;

use App\Enums\UserRole;
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
                && $metrics['response_bytes'] === strlen($response->getContent());
        }))->once();
    }
}
