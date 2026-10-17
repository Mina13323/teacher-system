<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * Persistent sign-in and the single active device policy, through real
 * bearer tokens (no actingAs): each request is authenticated the way the
 * app authenticates it after a reload or a browser restart.
 */
class DeviceSessionTest extends ApiTestCase
{
    use InteractsWithExams;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = $this->createUserWithRole(UserRole::Student);
    }

    /** A request as a fresh page load: no user remembered from an earlier request. */
    private function send(string $method, string $uri, array $data = [], ?string $token = null, string $agent = self::ANDROID): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $headers = ['User-Agent' => $agent, 'Authorization' => $token !== null ? "Bearer {$token}" : ''];

        return $this->withHeaders($headers)->json($method, $uri, $data);
    }

    private function login(User $user, string $agent = self::ANDROID): TestResponse
    {
        return $this->send('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], null, $agent);
    }

    private function signIn(User $user, string $agent = self::ANDROID): string
    {
        return $this->login($user, $agent)->assertOk()->json('data.token');
    }

    public function test_a_token_keeps_working_across_reloads_until_logout(): void
    {
        $token = $this->signIn($this->student);

        // Every reload restores the user from the stored token; no new login.
        foreach (range(1, 3) as $_) {
            $this->send('GET', '/api/v1/auth/me', [], $token)->assertOk()->assertJsonPath('data.id', $this->student->id);
        }
        $this->assertSame(1, $this->student->tokens()->count());

        $this->send('POST', '/api/v1/auth/logout', [], $token)->assertOk();
        $this->send('GET', '/api/v1/auth/me', [], $token)->assertStatus(401)->assertJsonMissingPath('code');
    }

    public function test_sign_ins_do_not_expire_by_default_even_when_long_unused(): void
    {
        $token = $this->signIn($this->student);
        $this->travel(400)->days();

        $this->send('GET', '/api/v1/auth/me', [], $token)->assertOk();
    }

    public function test_an_optional_idle_timeout_ends_unused_sign_ins_only(): void
    {
        config(['auth_sessions.idle_timeout_days' => 30]);
        $token = $this->signIn($this->student);

        $this->travel(20)->days();
        $this->send('GET', '/api/v1/auth/me', [], $token)->assertOk(); // refreshes last_used_at

        $this->travel(25)->days(); // 25 days since the last use
        $this->send('GET', '/api/v1/auth/me', [], $token)->assertOk();

        $this->travel(31)->days();
        $this->send('GET', '/api/v1/auth/me', [], $token)->assertStatus(401);

        // An idle sign-in does not count as another device.
        $this->login($this->student)->assertOk();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->signIn($this->student);
        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->send('GET', '/api/v1/auth/me', [], $token)->assertStatus(401);
    }

    public function test_roles_are_still_enforced_for_a_persistent_token(): void
    {
        $token = $this->signIn($this->student);

        $this->send('GET', '/api/v1/teacher/dashboard', [], $token)->assertStatus(403);
        $this->send('GET', '/api/v1/student/dashboard', [], $token)->assertOk();
    }

    public function test_a_second_device_must_confirm_and_the_first_stays_signed_in_until_then(): void
    {
        $first = $this->signIn($this->student, self::ANDROID);

        $response = $this->login($this->student, self::WINDOWS)
            ->assertStatus(409)
            ->assertJsonPath('code', 'session_transfer_required')
            ->assertJsonPath('data.other_session.device', 'Chrome on Android')
            ->assertJsonMissingPath('data.token');
        $this->assertNotEmpty($response->json('data.transfer_token'));
        $this->assertNotNull($response->json('data.other_session.last_active_at'));

        // Not confirmed: nothing was issued or revoked.
        $this->assertSame(1, $this->student->tokens()->count());
        $this->send('GET', '/api/v1/auth/me', [], $first)->assertOk();
    }

    public function test_a_wrong_password_on_a_second_device_reveals_nothing_and_changes_nothing(): void
    {
        $first = $this->signIn($this->student);

        $this->send('POST', '/api/v1/auth/login', ['email' => $this->student->email, 'password' => 'wrong'], null, self::WINDOWS)
            ->assertStatus(401)
            ->assertJsonMissingPath('data');

        $this->send('GET', '/api/v1/auth/me', [], $first)->assertOk();
    }

    public function test_confirming_moves_the_account_and_the_old_device_is_told_why(): void
    {
        $first = $this->signIn($this->student, self::ANDROID);
        $ticket = $this->login($this->student, self::WINDOWS)->json('data.transfer_token');

        $second = $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticket], null, self::WINDOWS)
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->student->id)
            ->json('data.token');

        $this->send('GET', '/api/v1/auth/me', [], $second)->assertOk();
        $this->send('GET', '/api/v1/auth/me', [], $first)
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_transferred');
        $this->send('GET', '/api/v1/student/dashboard', [], $first)->assertStatus(401);

        $this->assertSame(['Edge on Windows'], $this->student->tokens()->pluck('name')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.session_transfer']);
    }

    public function test_a_transfer_token_works_once(): void
    {
        $this->signIn($this->student);
        $ticket = $this->login($this->student, self::WINDOWS)->json('data.transfer_token');

        $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticket], null, self::WINDOWS)->assertOk();
        $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticket], null, self::WINDOWS)
            ->assertStatus(422)
            ->assertJsonPath('code', 'session_transfer_expired');

        $this->assertSame(1, $this->student->tokens()->count());
    }

    public function test_two_devices_confirming_at_once_leave_exactly_one_signed_in(): void
    {
        $this->signIn($this->student);
        $ticketB = $this->login($this->student, self::WINDOWS)->json('data.transfer_token');
        $ticketC = $this->login($this->student, self::ANDROID)->json('data.transfer_token');

        $tokenB = $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticketB], null, self::WINDOWS)->assertOk()->json('data.token');
        $tokenC = $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticketC], null, self::ANDROID)->assertOk()->json('data.token');

        $this->assertSame(1, $this->student->tokens()->count());
        $this->send('GET', '/api/v1/auth/me', [], $tokenB)->assertStatus(401)->assertJsonPath('code', 'session_transferred');
        $this->send('GET', '/api/v1/auth/me', [], $tokenC)->assertOk();
    }

    public function test_an_unknown_or_expired_transfer_token_revokes_nothing(): void
    {
        $first = $this->signIn($this->student);

        $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => str_repeat('x', 64)])
            ->assertStatus(422)->assertJsonPath('code', 'session_transfer_expired');
        $this->send('POST', '/api/v1/auth/session/transfer', [])->assertStatus(422);

        $ticket = $this->login($this->student, self::WINDOWS)->json('data.transfer_token');
        $this->travel(config('auth_sessions.transfer_ttl_seconds') + 1)->seconds();
        $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticket], null, self::WINDOWS)
            ->assertStatus(422);

        $this->send('GET', '/api/v1/auth/me', [], $first)->assertOk();
    }

    public function test_a_suspension_between_password_and_confirmation_blocks_the_move(): void
    {
        $first = $this->signIn($this->student);
        $ticket = $this->login($this->student, self::WINDOWS)->json('data.transfer_token');

        $this->student->forceFill(['is_active' => false])->save();

        $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticket], null, self::WINDOWS)
            ->assertStatus(403);
        // The first device's sign-in was not revoked.
        $this->assertSame(1, $this->student->tokens()->count());
        $this->assertTrue(PersonalAccessToken::findToken($first)?->exists ?? false);
    }

    public function test_staff_accounts_keep_several_devices(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $a = $this->signIn($teacher, self::ANDROID);
        $b = $this->signIn($teacher, self::WINDOWS);

        $this->send('GET', '/api/v1/auth/me', [], $a)->assertOk();
        $this->send('GET', '/api/v1/auth/me', [], $b)->assertOk();
    }

    public function test_the_policy_can_be_turned_off(): void
    {
        config(['auth_sessions.single_device_roles' => []]);

        $this->signIn($this->student, self::ANDROID);
        $this->signIn($this->student, self::WINDOWS);
        $this->assertSame(2, $this->student->tokens()->count());
    }

    public function test_existing_extra_sign_ins_are_not_revoked_until_a_confirmed_move(): void
    {
        // Accounts with several sign-ins from before the policy keep them.
        $a = $this->student->createToken('old phone')->plainTextToken;
        $b = $this->student->createToken('old laptop')->plainTextToken;

        $this->send('GET', '/api/v1/auth/me', [], $a)->assertOk();
        $this->send('GET', '/api/v1/auth/me', [], $b)->assertOk();
        $this->login($this->student, self::WINDOWS)->assertStatus(409);
        $this->send('GET', '/api/v1/auth/me', [], $a)->assertOk();
    }

    public function test_an_exam_attempt_survives_a_move_and_the_old_device_cannot_write(): void
    {
        Notification::fake();
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course, ['duration_minutes' => 30, 'max_attempts' => 1]);
        $question = $this->addSingleChoiceQuestion($exam);
        $options = $question->options()->orderBy('id')->pluck('id')->all();

        $old = $this->signIn($this->student, self::ANDROID);
        $this->send('POST', "/api/v1/student/courses/{$course->id}/enroll", [], $old)->assertStatus(201);
        $attempt = $this->send('POST', "/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true, 'compact_response' => true], $old)
            ->assertStatus(201)->json('data');
        $this->send('POST', "/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $question->id, 'option_ids' => [$options[0]]], $old)
            ->assertOk();

        $ticket = $this->login($this->student, self::WINDOWS)->json('data.transfer_token');
        $new = $this->send('POST', '/api/v1/auth/session/transfer', ['transfer_token' => $ticket], null, self::WINDOWS)->json('data.token');

        // The old device can no longer change the answer.
        $this->send('POST', "/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $question->id, 'option_ids' => [$options[1]]], $old)
            ->assertStatus(401);

        // The new device resumes the same attempt: same deadline, saved answer kept.
        $resumed = $this->send('POST', "/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true, 'compact_response' => true], $new)
            ->assertStatus(201)->json('data');
        $this->assertSame($attempt['id'], $resumed['id']);
        $this->assertTrue($resumed['already_open']);
        $this->assertSame($attempt['expires_at'], $resumed['expires_at']);

        $this->assertSame(1, ExamAttempt::where('student_id', $this->student->id)->count());
        $this->assertSame([$options[0]], ExamAnswer::where('attempt_id', $attempt['id'])->sole()->selectedOptionIds());

        $this->send('GET', "/api/v1/student/attempts/{$attempt['id']}", [], $new)
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->send('POST', "/api/v1/student/attempts/{$attempt['id']}/answers", ['question_id' => $question->id, 'option_ids' => [$options[1]]], $new)
            ->assertOk();
    }
}
