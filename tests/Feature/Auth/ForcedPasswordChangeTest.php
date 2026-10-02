<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

class ForcedPasswordChangeTest extends ApiTestCase
{
    public function test_temporary_credential_is_limited_to_password_change_until_completed(): void
    {
        $student = $this->createUserWithRole(UserRole::Student, [
            'email' => 'temporary@example.test',
            'password' => 'temporary-password',
            'must_change_password' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'temporary-password',
        ])->assertOk()
            ->assertJsonPath('data.user.must_change_password', true);

        $headers = ['Authorization' => 'Bearer '.$login->json('data.token')];

        $this->withHeaders($headers)
            ->getJson('/api/v1/student/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        // Identity, logout, and the password form remain reachable so the
        // restriction cannot lock a new account out of the recovery flow.
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/auth/profile')->assertOk();
        $this->withHeaders($headers)
            ->putJson('/api/v1/auth/profile', ['name' => 'Not allowed yet'])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'temporary-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'must_change_password' => false,
        ]);

        $this->withHeaders($headers)->getJson('/api/v1/student/dashboard')->assertOk();
    }
}
