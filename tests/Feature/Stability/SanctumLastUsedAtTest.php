<?php

namespace Tests\Feature\Stability;

use App\Enums\UserRole;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

class SanctumLastUsedAtTest extends ApiTestCase
{
    private function tokenUpdates(callable $callback): int
    {
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'personal_access_tokens')) {
                $updates++;
            }
        });
        $callback();

        return $updates;
    }

    public function test_last_used_at_is_written_at_most_once_per_window(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $student = $this->createUserWithRole(UserRole::Student);
        $plain = $student->createToken('t')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$plain];

        // First use: never recorded, so it is written.
        $this->assertSame(1, $this->tokenUpdates(fn () => $this->getJson('/api/v1/auth/me', $headers)->assertOk()));
        $this->app['auth']->forgetGuards();

        // Within the window: authenticated, but no write.
        Carbon::setTestNow('2026-10-08 12:04:00');
        $this->assertSame(0, $this->tokenUpdates(fn () => $this->getJson('/api/v1/auth/me', $headers)->assertOk()));
        $this->app['auth']->forgetGuards();
        $this->assertTrue(PersonalAccessToken::first()->last_used_at->equalTo(Carbon::parse('2026-10-08 12:00:00')));

        // After the window: written again.
        Carbon::setTestNow('2026-10-08 12:05:01');
        $this->assertSame(1, $this->tokenUpdates(fn () => $this->getJson('/api/v1/auth/me', $headers)->assertOk()));
        $this->assertTrue(PersonalAccessToken::first()->last_used_at->equalTo(Carbon::parse('2026-10-08 12:05:01')));

        Carbon::setTestNow();
    }

    public function test_token_validation_is_unchanged(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $plain = $student->createToken('t')->plainTextToken;

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertOk();
        $this->app['auth']->forgetGuards();

        // A wrong secret for a real token id is still rejected.
        [$id] = explode('|', $plain, 2);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$id.'|wrong'])->assertStatus(401);
        $this->app['auth']->forgetGuards();

        // A deleted (logged out / revoked) token is rejected.
        PersonalAccessToken::query()->delete();
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    }

    public function test_other_token_changes_are_still_saved(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $student->createToken('t');
        $token = PersonalAccessToken::firstOrFail();
        $token->forceFill(['last_used_at' => now()])->save();

        $token->name = 'renamed';
        $token->last_used_at = now();
        $token->save();

        $this->assertSame('renamed', $token->fresh()->name);
    }
}
