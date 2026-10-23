<?php

namespace Tests\Feature\Stability;

use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

/**
 * The SPA entry page is a static file. It must not start a Laravel session,
 * because with SESSION_DRIVER=database every page load would open a MySQL
 * connection just to read and write an unused session row.
 */
class SpaEntryWithoutSessionTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
    }

    public function test_the_spa_page_makes_no_database_queries_and_sets_no_session_cookie(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        foreach (['/', '/student/exams', '/teacher/exams/5', '/login'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertEmpty(
                array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === config('session.cookie')),
                "No session cookie expected for {$path}"
            );
            $this->assertEmpty(
                array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === 'XSRF-TOKEN'),
                "No CSRF cookie expected for {$path}"
            );
        }

        $this->assertSame(0, $queries);
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_api_routes_are_not_served_by_the_spa_route(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
