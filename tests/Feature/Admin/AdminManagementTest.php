<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Feature\ApiTestCase;

class AdminManagementTest extends ApiTestCase
{
    public function test_admin_can_create_a_teacher(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/teachers', [
                'name' => 'Sara Teacher',
                'email' => 'Sara@Example.com',
                'password' => 'secret123',
            ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Sara Teacher')
            ->assertJsonPath('data.email', 'sara@example.com');

        $this->assertDatabaseHas('users', ['email' => 'sara@example.com']);
        $this->assertTrue(User::where('email', 'sara@example.com')->firstOrFail()->hasRole(UserRole::Teacher->value));
    }

    public function test_teacher_cannot_access_admin_teacher_management(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/admin/teachers', [
                'name' => 'Should Fail',
                'email' => 'fail@example.com',
                'password' => 'secret123',
            ])->assertStatus(403);

        $this->assertDatabaseCount('users', 1); // only teacher created.
    }

    public function test_admin_can_list_teachers(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/teachers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_deactivate_a_teacher(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/teachers/{$teacher->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($teacher->fresh()->isActive());
    }

    public function test_admin_can_reset_a_teacher_password(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $originalPassword = $teacher->password;

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/teachers/{$teacher->id}/reset-password", [
                'password' => 'newsecret1',
                'password_confirmation' => 'newsecret1',
            ])->assertStatus(200);

        $this->assertNotSame($originalPassword, $teacher->fresh()->password);

        // Old password no longer works; the new one does.
        $this->postJson('/api/v1/auth/login', [
            'email' => $teacher->email,
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => $teacher->email,
            'password' => 'newsecret1',
        ])->assertStatus(200);
    }

    public function test_admin_can_search_students_by_name_code_email_and_status(): void
    {
        $admin = $this->createUserWithRole(UserRole::Admin);

        $studentA = User::factory()->create([
            'name' => 'Alaa Ibrahim',
            'email' => 'alaa@student.test',
            'student_code' => 'STU-1001',
            'phone' => '+201011111111',
            'is_active' => true,
        ]);
        $studentA->assignRole(UserRole::Student->value);

        $studentB = User::factory()->create([
            'name' => 'Rene Hany',
            'email' => 'rene@student.test',
            'student_code' => 'STU-1002',
            'phone' => '+201022222222',
            'is_active' => false,
        ]);
        $studentB->assignRole(UserRole::Student->value);

        // Search by name
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/students?search=Alaa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentA->id);

        // Search by student code
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/students?search=STU-1002')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentB->id);

        // Filter by active status
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/students?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentA->id);

        // Filter by inactive status
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/students?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $studentB->id);
    }
}
