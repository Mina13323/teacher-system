<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

class LoginRateLimitTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('api.rate_limit.auth', 1);
    }

    public function test_email_and_student_code_share_the_same_account_limit_across_ips(): void
    {
        $student = $this->createUserWithRole(UserRole::Student, [
            'email' => 'rate-limit@example.test',
            'student_code' => 'ELM-7812',
            'password' => 'correct-password',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->postJson('/api/v1/auth/login', [
                'email' => $student->email,
                'password' => 'incorrect-password',
            ])
            ->assertUnauthorized();

        // Changing the source IP and login identifier must not bypass the
        // account budget by using the student's alternate login code.
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->postJson('/api/v1/auth/login', [
                'student_code' => $student->student_code,
                'password' => 'correct-password',
            ])
            ->assertStatus(429);
    }

    public function test_alias_logins_for_different_accounts_do_not_share_an_empty_identifier_bucket(): void
    {
        $studentA = $this->createUserWithRole(UserRole::Student, [
            'email' => 'first@example.test',
            'student_code' => 'ELM-7813',
            'password' => 'correct-password',
        ]);
        $studentB = $this->createUserWithRole(UserRole::Student, [
            'email' => 'second@example.test',
            'student_code' => 'ELM-7814',
            'password' => 'correct-password',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])
            ->postJson('/api/v1/auth/login', [
                'login' => $studentA->student_code,
                'password' => 'correct-password',
            ])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.21'])
            ->postJson('/api/v1/auth/login', [
                'student_code' => $studentB->student_code,
                'password' => 'correct-password',
            ])
            ->assertOk();
    }
}
