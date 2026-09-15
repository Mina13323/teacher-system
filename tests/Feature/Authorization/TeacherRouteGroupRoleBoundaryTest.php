<?php

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

/**
 * The teacher route group carries a Spatie role boundary:
 *
 *     Route::prefix('teacher')->middleware(['auth:sanctum', 'role:teacher|assistant|admin'])
 *
 * This is a coarse gate in front of the per-controller authorization that
 * already existed. It must not weaken or replace that authorization, and it must
 * not lock out staff who legitimately use these endpoints.
 *
 * Verified against spatie/laravel-permission 6.25.0 (composer.lock):
 * RoleMiddleware does explode('|', $role) then hasAnyRole($roles), and failures
 * raise UnauthorizedException, which extends HttpException with status 403 and
 * is rendered by the HttpException handler in bootstrap/app.php.
 */
class TeacherRouteGroupRoleBoundaryTest extends ApiTestCase
{
    /**
     * GET /teacher/students is reachable by every staff role:
     * StudentPolicy::viewAny allows teacher, admin, assistant and the
     * students.view permission.
     */
    private const STAFF_ENDPOINT = '/api/v1/teacher/students';

    /**
     * GET /teacher/assistants additionally requires the assistants.view
     * permission via AssistantController::authorizeStaff(). Assistants are
     * deliberately NOT granted it (see PermissionSeeder::assignAssistantPermissions).
     */
    private const ASSISTANT_MANAGEMENT_ENDPOINT = '/api/v1/teacher/assistants';

    public function test_student_is_rejected_by_the_role_boundary(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson(self::STAFF_ENDPOINT)
            ->assertStatus(403);
    }

    public function test_student_is_rejected_from_assistant_management_too(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($student, 'sanctum')
            ->getJson(self::ASSISTANT_MANAGEMENT_ENDPOINT)
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_is_401_not_403(): void
    {
        $this->getJson(self::STAFF_ENDPOINT)
            ->assertStatus(401);
    }

    public function test_teacher_can_reach_the_staff_endpoint(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacher, 'sanctum')
            ->getJson(self::STAFF_ENDPOINT)
            ->assertStatus(200);
    }

    /**
     * Assistants are operational staff and must keep working. Adding the role
     * middleware must not have locked them out of the endpoints they are
     * entitled to.
     */
    public function test_assistant_can_still_reach_the_staff_endpoint(): void
    {
        $this->createUserWithRole(UserRole::Teacher);
        $assistant = $this->createUserWithRole(UserRole::Assistant);

        $this->actingAs($assistant, 'sanctum')
            ->getJson(self::STAFF_ENDPOINT)
            ->assertStatus(200);
    }

    /**
     * Admin bypass is preserved via Policy::before(), which returns true for
     * the admin role.
     */
    public function test_admin_can_reach_the_staff_endpoint(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::STAFF_ENDPOINT)
            ->assertStatus(200);
    }

    /**
     * The group middleware is not a substitute for the controller's own
     * authorization: an assistant passes the role boundary but is still refused
     * assistant management, because it lacks assistants.view.
     */
    public function test_assistant_passes_the_boundary_but_is_still_denied_by_the_controller(): void
    {
        $assistant = $this->createUserWithRole(UserRole::Assistant);

        $this->assertFalse($assistant->hasPermissionTo('assistants.view'));

        $this->actingAs($assistant, 'sanctum')
            ->getJson(self::ASSISTANT_MANAGEMENT_ENDPOINT)
            ->assertStatus(403);
    }

    /**
     * A teacher does hold assistants.view, so the same endpoint succeeds. This
     * proves the 403 above came from the permission check and not from the
     * group middleware.
     */
    public function test_teacher_holds_the_permission_and_can_manage_assistants(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->assertTrue($teacher->hasPermissionTo('assistants.view'));

        $this->actingAs($teacher, 'sanctum')
            ->getJson(self::ASSISTANT_MANAGEMENT_ENDPOINT)
            ->assertStatus(200);
    }
}
