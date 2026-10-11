<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\TeacherMessageNotification;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

/**
 * B2 — the unread badge count rides on GET /auth/me (and the login
 * response) instead of a separate request. It is the token user's own count,
 * computed in one place (User::unreadNotificationCount), and the separate
 * endpoint keeps working.
 */
class UnreadCountTest extends ApiTestCase
{
    private function notifyTimes(User $user, int $times): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        for ($i = 0; $i < $times; $i++) {
            $user->notify(new TeacherMessageNotification($teacher, "Message {$i}"));
        }
    }

    public function test_me_reports_the_users_own_unread_count(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->notifyTimes($student, 3);

        $this->actingAs($student, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $student->id)
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_users_never_see_each_others_count(): void
    {
        $a = $this->createUserWithRole(UserRole::Student);
        $b = $this->createUserWithRole(UserRole::Teacher);
        $this->notifyTimes($a, 2);

        $this->actingAs($a, 'sanctum')->getJson('/api/v1/auth/me')->assertJsonPath('data.unread_count', 2);
        $this->actingAs($b, 'sanctum')->getJson('/api/v1/auth/me')->assertJsonPath('data.unread_count', 0);
        $this->actingAs($b, 'sanctum')->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_unauthenticated_requests_stay_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
    }

    public function test_reading_one_and_reading_all_update_the_count(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->notifyTimes($student, 3);
        $api = $this->actingAs($student, 'sanctum');

        $id = $api->getJson('/api/v1/notifications')->json('data.data.0.id');
        $api->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $api->getJson('/api/v1/auth/me')->assertJsonPath('data.unread_count', 2);

        $api->postJson('/api/v1/notifications/read-all')->assertOk();
        $api->getJson('/api/v1/auth/me')->assertJsonPath('data.unread_count', 0);
        $api->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_login_carries_the_signing_in_users_count(): void
    {
        $student = $this->createUserWithRole(UserRole::Student, ['password' => bcrypt('secret123')]);
        $this->notifyTimes($student, 4);

        $this->postJson('/api/v1/auth/login', ['email' => $student->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email'], 'unread_count']])
            ->assertJsonPath('data.unread_count', 4);
    }

    public function test_me_keeps_every_existing_field(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $data = $this->actingAs($student, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->json('data');

        foreach (['id', 'name', 'email', 'student_code', 'roles', 'is_active', 'must_change_password', 'can_access_lessons',
            'can_take_exams', 'can_join_competitions', 'has_active_access', 'access_status', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
        $this->assertSame(['student'], $data['roles']);
    }

    public function test_me_adds_a_single_count_query(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->notifyTimes($student, 2);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($student, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        $queries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '"notifications"'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    public function test_the_separate_endpoint_and_me_agree(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->notifyTimes($student, 5);
        $api = $this->actingAs($student, 'sanctum');

        $this->assertSame(
            $api->getJson('/api/v1/notifications/unread-count')->json('data.unread_count'),
            $api->getJson('/api/v1/auth/me')->json('data.unread_count'),
        );
    }
}
