<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\ApiTestCase;

class PasswordRecoveryTest extends ApiTestCase
{
    private function student(array $overrides = []): User
    {
        return $this->createUserWithRole(UserRole::Student, array_merge([
            'student_code' => 'STU-1001',
            'phone' => '01012345678',
            'password' => 'old-password-1',
        ], $overrides));
    }

    /** Signs the student in, takes the code from the app and confirms it. */
    private function saveRecoveryCode(User $student, string $password = 'old-password-1'): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => $password,
        ])->assertOk()
            ->assertJsonPath('data.user.recovery_code_pending', true)
            ->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $code = $this->withHeaders($headers)
            ->postJson('/api/v1/student/recovery-code')
            ->assertOk()
            ->json('data.recovery_code');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        $this->withHeaders($headers)->postJson('/api/v1/student/recovery-code/confirm')->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();

        return $code;
    }

    /** Requests as one bearer token; the test client otherwise keeps the previous user cached. */
    private function withBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    private function recover(array $payload)
    {
        return $this->postJson('/api/v1/auth/recover', array_merge([
            'student_code' => 'STU-1001',
            'phone' => '+201012345678',
        ], $payload));
    }

    public function test_code_is_stored_hashed_and_only_usable_after_it_is_confirmed(): void
    {
        $student = $this->student();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'old-password-1',
        ])->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token];

        $code = $this->withHeaders($headers)->postJson('/api/v1/student/recovery-code')->json('data.recovery_code');

        $stored = $student->fresh()->recovery_code_hash;
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString(str_replace('-', '', $code), $stored);

        // Shown but not yet confirmed as saved: it cannot recover the account.
        $this->recover(['recovery_code' => $code])->assertUnauthorized();

        $this->withHeaders($headers)->postJson('/api/v1/student/recovery-code/confirm')->assertOk();
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.recovery_code_pending', false);

        // Once saved it is never issued again.
        $this->withHeaders($headers)->postJson('/api/v1/student/recovery-code')->assertStatus(409);
    }

    public function test_correct_code_and_phone_sign_in_and_force_a_new_password(): void
    {
        $student = $this->student();
        $code = $this->saveRecoveryCode($student);

        // Typing it in lower case, without dashes, with another phone format is fine.
        $response = $this->recover([
            'recovery_code' => strtolower(str_replace('-', '', $code)),
            'phone' => '+20 101 234 5678',
        ])->assertOk()
            ->assertJsonPath('data.user.must_change_password', true)
            ->assertJsonPath('data.user.password_recovery', true);

        $token = $response->json('data.token');

        // The session can only choose a new password.
        $this->withBearer($token)->getJson('/api/v1/student/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        // No current password is asked for, but the new one is still validated.
        $this->withBearer($token)->putJson('/api/v1/auth/password', [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable();

        $this->withBearer($token)->putJson('/api/v1/auth/password', [
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->withBearer($token)->getJson('/api/v1/student/dashboard')->assertOk();

        $this->withBearer($token)->postJson('/api/v1/auth/logout')->assertOk();

        // The old password is gone, the new one works.
        $this->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'old-password-1'])
            ->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'brand-new-password'])
            ->assertOk()
            // The used code is retired; a new one is shown on this visit.
            ->assertJsonPath('data.user.recovery_code_pending', true);

        // The used code no longer works.
        $this->recover(['recovery_code' => $code])->assertUnauthorized();
    }

    public function test_recovery_ends_other_sign_ins(): void
    {
        $student = $this->student();
        $code = $this->saveRecoveryCode($student);

        $other = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'old-password-1',
        ])->json('data.token');

        $this->recover(['recovery_code' => $code])->assertOk();

        $this->withBearer($other)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_every_wrong_detail_gets_the_same_contact_admin_answer(): void
    {
        $student = $this->student();
        $code = $this->saveRecoveryCode($student);

        $message = null;
        foreach ([
            ['recovery_code' => 'AAAA-AAAA-AAAA'],
            ['recovery_code' => $code, 'phone' => '01112345678'],
            ['recovery_code' => $code, 'student_code' => 'NOPE-0000'],
        ] as $payload) {
            $response = $this->recover($payload)->assertUnauthorized();
            $message ??= $response->json('message');
            $this->assertSame($message, $response->json('message'));
        }

        $this->assertStringContainsString('contact the admin', $message);
        $this->assertFalse($student->fresh()->must_change_password);
    }

    public function test_student_without_a_phone_on_file_cannot_recover(): void
    {
        $student = $this->student(['phone' => null]);
        $code = $this->saveRecoveryCode($student);

        $this->recover(['recovery_code' => $code])->assertUnauthorized();
    }

    public function test_suspended_student_cannot_recover(): void
    {
        $student = $this->student();
        $code = $this->saveRecoveryCode($student);
        $student->forceFill(['is_active' => false])->save();

        $this->recover(['recovery_code' => $code])->assertForbidden();
    }

    public function test_staff_reset_retires_the_code_and_a_new_one_is_shown(): void
    {
        $student = $this->student();
        $code = $this->saveRecoveryCode($student);

        app(\App\Actions\Auth\ResetUserPasswordAction::class)->execute($student->fresh(), 'temporary-pass-9');

        $this->recover(['recovery_code' => $code])->assertUnauthorized();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email,
            'password' => 'temporary-pass-9',
        ])->assertOk()->json('data.token');

        // Their normal flow (current password required) still works.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson('/api/v1/auth/password', [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertUnprocessable();
    }

    public function test_recovery_flags_in_the_user_payload_cost_no_database_queries(): void
    {
        $student = $this->student();
        $user = User::with('roles', 'latestAccessPeriod')->find($student->id);

        // The payload already loaded roles and the access period; the recovery flags add nothing.
        $user->hasActiveAccess();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $user->needsRecoveryCode();
        $user->isRecoveringPassword();
        $payload = (new \App\Http\Resources\UserResource($user))->resolve(request());

        $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog());
        $this->assertTrue($payload['recovery_code_pending']);
        $this->assertFalse($payload['password_recovery']);
    }

    public function test_recovery_attempts_are_rate_limited(): void
    {
        RateLimiter::clear('recovery|code|'.hash('sha256', 'STU-1001'));
        $this->student();

        for ($i = 0; $i < 5; $i++) {
            $this->recover(['recovery_code' => 'AAAA-AAAA-AAAA'])->assertUnauthorized();
        }

        $this->recover(['recovery_code' => 'AAAA-AAAA-AAAA'])->assertStatus(429);
    }
}
