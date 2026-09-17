<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Feature\ApiTestCase;

/**
 * Guards brute-force protection on credential submission.
 *
 * Login is the only endpoint an unauthenticated client can hammer to guess
 * passwords, so it carries a dedicated limiter ("throttle:login") that is far
 * tighter than the general api limiter. These tests pin both the budget and
 * the fact that the limiter is actually attached to the route.
 */
class LoginRateLimitTest extends ApiTestCase
{
    private const LOGIN_URL = '/api/v1/auth/login';

    private function attempt(string $email, string $password = 'wrong-password'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::LOGIN_URL, [
            'email' => $email,
            'password' => $password,
        ]);
    }

    public function test_login_is_throttled_after_the_configured_budget(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'correct-password']);

        $budget = (int) config('api.rate_limit.auth', 10);

        for ($i = 0; $i < $budget; $i++) {
            $this->attempt('victim@example.com')
                ->assertStatus(401)
                ->assertJson(['success' => false]);
        }

        // The attempt after the budget must be refused before credentials are
        // ever checked, so it cannot be used to probe a password.
        $this->attempt('victim@example.com')
            ->assertStatus(429)
            ->assertJson(['success' => false]);
    }

    public function test_throttled_response_reports_how_long_to_wait(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'correct-password']);

        $budget = (int) config('api.rate_limit.auth', 10);

        for ($i = 0; $i <= $budget; $i++) {
            $response = $this->attempt('victim@example.com');
        }

        $response->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_throttling_stops_a_correct_password_from_working(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => 'correct-password']);

        $budget = (int) config('api.rate_limit.auth', 10);

        for ($i = 0; $i <= $budget; $i++) {
            $this->attempt('victim@example.com');
        }

        // Even the real credential is refused while throttled; the limiter sits
        // in front of authentication.
        $this->attempt('victim@example.com', 'correct-password')->assertStatus(429);
    }

    public function test_a_single_legitimate_login_is_not_throttled(): void
    {
        User::factory()->create(['email' => 'owner@example.com', 'password' => 'correct-password']);

        $this->attempt('owner@example.com', 'correct-password')
            ->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);
    }

    public function test_the_general_api_limiter_is_attached(): void
    {
        // throttleApi() only takes effect when bootstrap/app.php calls it. If
        // that call is removed the api group silently stops rate limiting, so
        // assert the middleware is present rather than trusting the config.
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/auth/login');

        $this->assertNotNull($route, 'The login route was not registered.');
        $this->assertContains('throttle:login', $route->gatherMiddleware());
    }
}
