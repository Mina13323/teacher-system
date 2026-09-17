<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;

/**
 * Every list endpoint resolves its page size through the shared, capped
 * Controller::perPage() helper.
 *
 * The paginator sits inside the API envelope, so the page size is asserted at
 * `data.meta.per_page` — `meta` is not top-level.
 *
 * `per_page` is client-supplied, so an uncapped value lets any caller request an
 * entire table in a single response and force the server to hydrate every row.
 * These tests pin the cap, the fallback for nonsense values, and the fact that a
 * legitimate page size is still honoured.
 */
class PaginationTest extends ApiTestCase
{
    public function test_page_size_is_capped_on_the_course_catalog(): void
    {
        $user = $this->createUserWithRole(UserRole::Student);
        Course::factory()->count(105)->create(['status' => 'published']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/courses?per_page=1000000')
            ->assertStatus(200);

        $this->assertCount(100, $response->json('data'));
    }

    public function test_page_size_is_capped_on_a_teacher_endpoint(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        for ($i = 0; $i < 105; $i++) {
            $st = $this->createUserWithRole(UserRole::Student, ['created_by' => $teacher->id]);
        }

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students?per_page=5000')
            ->assertStatus(200);

        $this->assertCount(100, $response->json('data'));
    }

    public function test_a_zero_or_negative_page_size_falls_back_to_the_default(): void
    {
        $user = $this->createUserWithRole(UserRole::Student);
        Course::factory()->count(20)->create(['status' => 'published']);

        // Laravel would otherwise pass 0 straight through to the paginator.
        $res0 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/courses?per_page=0')
            ->assertStatus(200);
        $this->assertCount(15, $res0->json('data'));

        $resNeg = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/courses?per_page=-5')
            ->assertStatus(200);
        $this->assertCount(15, $resNeg->json('data'));
    }

    public function test_a_legitimate_page_size_is_honoured(): void
    {
        $user = $this->createUserWithRole(UserRole::Student);
        Course::factory()->count(10)->create(['status' => 'published']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/courses?per_page=7')
            ->assertStatus(200);

        $this->assertCount(7, $response->json('data'));
    }

    public function test_the_default_page_size_applies_when_none_is_requested(): void
    {
        $user = $this->createUserWithRole(UserRole::Student);
        Course::factory()->count(20)->create(['status' => 'published']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/courses')
            ->assertStatus(200);

        $this->assertCount(15, $response->json('data'));
    }

    /**
     * The cap must hold on the endpoint that can carry the most rows: a course
     * list owned by a teacher with many courses.
     */
    public function test_the_cap_holds_for_a_teacher_listing_their_courses(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        Course::factory()->count(105)->create(['created_by' => $teacher->id]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/courses?per_page=99999')
            ->assertStatus(200);

        $this->assertCount(100, $response->json('data'));
    }
}
