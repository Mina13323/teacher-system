<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\ScheduledReminderNotification;
use App\Services\Push\WebPushSender;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P2 — Web Push: subscription management (idempotent, IDOR-safe), graceful
 * fallback to in-app notifications, and best-effort delivery with dead
 * endpoint cleanup. The push channel NEVER replaces the database
 * notification; it piggybacks on the same reminder with the same gating.
 */
class WebPushTest extends ApiTestCase
{
    use InteractsWithExams;

    private function fakeSubscription(User $user, string $endpoint = 'https://push.example/ep-1'): PushSubscription
    {
        return PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
        ]);
    }

    private function subscribedExamScenario(): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(5),
        ]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);
        $this->fakeSubscription($student);

        return [$teacher, $course, $exam, $student];
    }

    // ---- Subscription management -------------------------------------------

    public function test_subscription_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/push-subscriptions')->assertStatus(401);
        $this->postJson('/api/v1/push-subscriptions', [])->assertStatus(401);
    }

    public function test_subscribe_is_idempotent_per_endpoint(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $payload = [
            'endpoint' => 'https://push.example/ep-unique',
            'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
        ];

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/push-subscriptions', $payload)->assertStatus(201);
        $this->actingAs($student, 'sanctum')->postJson('/api/v1/push-subscriptions', $payload)->assertStatus(201);

        $this->assertSame(1, PushSubscription::where('endpoint', $payload['endpoint'])->count());

        $index = $this->actingAs($student, 'sanctum')->getJson('/api/v1/push-subscriptions')->assertStatus(200);
        $this->assertCount(1, $index->json('data.subscriptions'));
    }

    public function test_unsubscribe_only_touches_own_subscription(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $other = $this->createUserWithRole(UserRole::Student);
        $mine = $this->fakeSubscription($student, 'https://push.example/mine');
        $theirs = $this->fakeSubscription($other, 'https://push.example/theirs');

        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/v1/push-subscriptions/{$theirs->id}")->assertStatus(403);
        $this->assertNotNull(PushSubscription::find($theirs->id));

        $this->actingAs($student, 'sanctum')
            ->deleteJson("/api/v1/push-subscriptions/{$mine->id}")->assertStatus(200);
        $this->assertNull(PushSubscription::find($mine->id));
    }

    // ---- Delivery: fallback + gating ---------------------------------------

    public function test_reminders_fall_back_to_in_app_notifications_without_vapid(): void
    {
        [$teacher, $course, $exam, $student] = $this->subscribedExamScenario();

        $recording = new RecordingSender();
        $this->app->instance(WebPushSender::class, $recording);

        $this->artisan('reminders:dispatch')->assertExitCode(0);

        // Database notification is the durable record even with no VAPID keys.
        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $student->id)
            ->where('type', ScheduledReminderNotification::class)
            ->count());
        $this->assertSame(0, $recording->calls);
    }

    public function test_reminders_push_when_enabled_and_respect_quiet_hours(): void
    {
        [$teacher, $course, $exam, $student] = $this->subscribedExamScenario();

        $recording = new RecordingSender();
        $recording->enabled = true;
        $this->app->instance(WebPushSender::class, $recording);

        $this->artisan('reminders:dispatch')->assertExitCode(0);
        $this->assertSame(1, $recording->calls);
        $this->assertSame('exam_opening', $recording->lastData['kind'] ?? null);

        // Quiet hours: a NEW reminder must be suppressed AND retried later —
        // no push, no dedupe row for the suppressed event.
        $exam2 = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(6),
        ]);
        $student->notification_preferences = [
            'quiet_hours' => ['start' => '00:00', 'end' => '23:59'],
        ];
        $student->save();

        $this->artisan('reminders:dispatch')->assertExitCode(0);
        $this->assertSame(1, $recording->calls, 'quiet hours must suppress the push too');

        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $student->id)
            ->where('type', ScheduledReminderNotification::class)
            ->where('data', 'like', '%exam_opening%')
            ->count(), 'suppressed reminder must not be recorded (retry next run)');
    }

    // ---- Encryption self-test + dead endpoint cleanup -----------------------

    public function test_payload_encryption_and_dead_endpoint_cleanup(): void
    {
        // "Browser" side: real P-256 key pair for the subscription.
        $browserKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $browser = openssl_pkey_get_details($browserKey);
        $p256dh = rtrim(strtr(base64_encode("\x04" . $browser['ec']['x'] . $browser['ec']['y']), '+/', '-_'), '=');
        $auth = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');

        // Server side: VAPID key pair.
        $serverKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $server = openssl_pkey_get_details($serverKey);
        config([
            'push.vapid_private_key' => $server['key'],
            'push.vapid_public_key' => rtrim(strtr(base64_encode("\x04" . $server['ec']['x'] . $server['ec']['y']), '+/', '-_'), '='),
            'push.vapid_subject' => 'mailto:test@example.com',
        ]);

        $student = $this->createUserWithRole(UserRole::Student);
        $sub = PushSubscription::create([
            'user_id' => $student->id,
            'endpoint' => 'https://push.example/gone',
            'keys' => ['p256dh' => $p256dh, 'auth' => $auth],
        ]);

        $seen = [];
        $sender = new WebPushSender(function (string $url, array $headers, string $body) use (&$seen): array {
            $seen[] = compact('url', 'headers', 'body');

            return [410, ''];
        });

        $results = $sender->sendToUser($student, 'Title', 'Body', '/student/notifications', ['kind' => 'exam_opening']);

        $this->assertSame(410, $results[0]['status']);
        // aes128gcm framing: salt(16) + rs(4) + idlen(1) + key(65) + tag(16) minimum.
        $this->assertGreaterThan(16 + 4 + 1 + 65 + 16, strlen($seen[0]['body']));
        $headerString = implode('|', $seen[0]['headers']);
        $this->assertStringContainsString('Content-Encoding: aes128gcm', $headerString);
        // VAPID Authorization header present.
        $this->assertStringContainsString('vapid t=', $headerString);
        // Dead endpoint removed.
        $this->assertNull(PushSubscription::find($sub->id));
    }
}

/**
 * Test double: records calls, reports disabled by default (fallback contract).
 */
class RecordingSender extends WebPushSender
{
    public int $calls = 0;

    public bool $enabled = false;

    public array $lastData = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function sendToUser(User $user, string $title, string $body, ?string $url = null, array $data = []): array
    {
        $this->calls++;
        $this->lastData = $data;

        return [];
    }
}
