<?php

namespace Tests\Feature\Stability;

use App\Services\Observability\ErrorCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\Feature\ApiTestCase;

class DatabaseRefusalResponseTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('_test/refused-query', fn () => throw new QueryException(
                'mysql',
                'select * from `personal_access_tokens` where `id` = ? limit 1',
                [1],
                new PDOException('SQLSTATE[HY000] [2002] Operation not permitted'),
            ));
            Route::get('_test/refused-pdo', fn () => throw new PDOException('SQLSTATE[HY000] [2002] Operation not permitted'));
            Route::get('_test/query-failure', fn () => throw new QueryException(
                'mysql',
                'select 1',
                [],
                new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found'),
            ));
        });
    }

    public function test_a_refused_connection_is_a_503_with_retry_after(): void
    {
        foreach (['_test/refused-query', '_test/refused-pdo'] as $uri) {
            $this->getJson('/api/v1/'.$uri)
                ->assertStatus(503)
                ->assertHeader('Retry-After', '5')
                ->assertJson(['success' => false, 'code' => 'service_busy'])
                ->assertJsonMissingPath('exception');
        }
    }

    public function test_the_response_never_leaks_sql(): void
    {
        $body = $this->getJson('/api/v1/_test/refused-query')->getContent();

        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('personal_access_tokens', $body);
    }

    public function test_other_database_errors_keep_their_500(): void
    {
        $this->getJson('/api/v1/_test/query-failure')->assertStatus(500);
    }

    public function test_the_request_log_carries_an_error_category(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            if (in_array($event->message, ['api.request.completed', 'api.request.slow'], true)) {
                $logged[] = $event->context['error_category'] ?? null;
            }
        });

        $this->getJson('/api/v1/_test/refused-query');
        $this->getJson('/api/v1/_test/query-failure');
        $this->getJson('/api/v1/auth/me');

        $this->assertSame([
            ErrorCategory::DB_CONNECT_FINAL,
            ErrorCategory::DB_QUERY_FAILURE,
            ErrorCategory::AUTH_FAILURE,
        ], $logged);
    }

    public function test_classification(): void
    {
        $this->assertNull(ErrorCategory::classify(200));
        $this->assertNull(ErrorCategory::classify(422));
        $this->assertSame(ErrorCategory::RATE_LIMITED, ErrorCategory::classify(429));
        $this->assertSame(ErrorCategory::SERVER_ERROR, ErrorCategory::classify(500));
        $this->assertSame(ErrorCategory::EXAM_STATE_REJECTED, ErrorCategory::classify(422, new \App\Exceptions\InvalidAttemptStateException('closed')));
    }
}
