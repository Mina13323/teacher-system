<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

class ArabicErrorMessagesTest extends ApiTestCase
{
    public function test_errors_are_translated_when_the_app_asks_for_arabic(): void
    {
        $student = $this->createUserWithRole(UserRole::Student, ['password' => 'old-password-1']);

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'wrong'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'بيانات الدخول غير صحيحة.');

        // Laravel's own validation messages come out in Arabic too.
        $this->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'فشل التحقق من البيانات.');
    }

    public function test_english_and_missing_language_are_unchanged(): void
    {
        $student = $this->createUserWithRole(UserRole::Student, ['password' => 'old-password-1']);

        foreach ([[], ['Accept-Language' => 'en']] as $headers) {
            $this->withHeaders($headers)
                ->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'wrong'])
                ->assertUnauthorized()
                ->assertJsonPath('message', 'The provided credentials are incorrect.');
        }
    }

    public function test_arabic_request_does_not_leak_into_the_next_english_one(): void
    {
        $student = $this->createUserWithRole(UserRole::Student, ['password' => 'old-password-1']);

        $this->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'wrong'])->assertUnauthorized();

        // The test client keeps headers between calls; a real browser sends its own each time.
        $this->flushHeaders();
        $this->assertSame('en', app()->getLocale());

        $this->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'wrong'])
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }
}
