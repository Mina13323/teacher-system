<?php

namespace Tests\Feature\Student;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Feature\ApiTestCase;

/**
 * Teacher-owned LMS student lifecycle: a teacher creates the student account,
 * the student can authenticate, complete/update their own profile, and the
 * teacher/admin manage the account. Cross-teacher access to another teacher's
 * student is denied.
 */
class StudentLifecycleTest extends ApiTestCase
{
    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    public function test_teacher_can_create_a_student_account(): void
    {
        $teacher = $this->makeTeacher();

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'Mina@Example.com',
                'password' => 'secret123',
                'phone' => '+201234567890',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Mina Walid')
            ->assertJsonPath('data.email', 'mina@example.com')
            ->assertJsonMissing(['password' => 'secret123']);

        $this->assertDatabaseHas('users', [
            'email' => 'mina@example.com',
            'created_by' => $teacher->id,
        ]);

        $user = User::where('email', 'mina@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(UserRole::Student->value));
        $this->assertTrue($user->isActive());
    }

    public function test_student_can_login_after_teacher_created_the_account(): void
    {
        $teacher = $this->makeTeacher();

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        // The student can now authenticate with the teacher-assigned credentials.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'mina@example.com',
            'password' => 'secret123',
        ])->assertStatus(200)
            ->assertJsonPath('data.user.roles.0', 'student')
            ->assertJsonMissing(['password' => 'secret123']);
    }

    public function test_student_can_update_their_own_profile(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'mina@example.com')->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/v1/auth/profile', [
                'name' => 'Mina W.',
                'bio' => 'Marketing student',
                'phone' => '+201111111111',
            ])->assertStatus(200)
            ->assertJsonPath('data.bio', 'Marketing student')
            ->assertJsonPath('data.phone', '+201111111111')
            ->assertJsonPath('data.profile_completed', true);

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'name' => 'Mina W.',
            'bio' => 'Marketing student',
        ]);
    }

    public function test_student_can_change_their_own_password(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'mina@example.com')->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'secret123',
                'password' => 'newsecret1',
                'password_confirmation' => 'newsecret1',
            ])->assertStatus(200);

        // Old password no longer works.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'mina@example.com',
            'password' => 'secret123',
        ])->assertStatus(401);

        // New password works.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'mina@example.com',
            'password' => 'newsecret1',
        ])->assertStatus(200);
    }

    public function test_teacher_cannot_update_another_teachers_student(): void
    {
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();

        $this->actingAs($teacherA, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'mina@example.com')->firstOrFail();

        // Teacher B does not manage this student -> 403.
        $this->actingAs($teacherB, 'sanctum')
            ->putJson("/api/v1/teacher/students/{$student->id}", [
                'name' => 'Hijacked',
            ])->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $student->id, 'name' => 'Mina Walid']);
    }

    public function test_student_can_get_their_own_profile(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
                'phone' => '+201234567890',
            ])->assertStatus(201);

        $student = User::where('email', 'mina@example.com')->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '+201234567890')
            ->assertJsonPath('data.profile_completed', true)
            ->assertJsonMissing(['password' => 'secret123']);
    }

    public function test_teacher_sees_only_students_they_manage(): void
    {
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();

        $this->actingAs($teacherA, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'A Student',
                'email' => 'a@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);
        $this->actingAs($teacherB, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'B Student',
                'email' => 'b@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        // Teacher A only sees their own student.
        $this->actingAs($teacherA, 'sanctum')
            ->getJson('/api/v1/teacher/students')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'a@example.com');
    }

    public function test_teacher_can_deactivate_a_student_they_created(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'mina@example.com')->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/teacher/students/{$student->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($student->fresh()->isActive());

        // A deactivated account cannot log in.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'mina@example.com',
            'password' => 'secret123',
        ])->assertStatus(403);
    }

    public function test_admin_can_manage_any_student(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Mina Walid',
                'email' => 'mina@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'mina@example.com')->firstOrFail();
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/students/{$student->id}", [
                'name' => 'Admin-edited',
            ])->assertStatus(200)
            ->assertJsonPath('data.name', 'Admin-edited');
    }

    public function test_student_cannot_access_other_students_profile_via_admin_or_teacher(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'A',
                'email' => 'a@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'B',
                'email' => 'b@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $studentA = User::where('email', 'a@example.com')->firstOrFail();
        $studentB = User::where('email', 'b@example.com')->firstOrFail();

        // Student A cannot reach the admin management surface for student B.
        $this->actingAs($studentA, 'sanctum')
            ->getJson("/api/v1/admin/students/{$studentB->id}")
            ->assertStatus(403);

        // Student A cannot reach the teacher management surface for student B.
        $this->actingAs($studentA, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$studentB->id}")
            ->assertStatus(403);
    }

    public function test_teacher_can_delete_student_they_manage(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'To Delete',
                'email' => 'todelete@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'todelete@example.com')->firstOrFail();

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
    }

    public function test_teacher_cannot_delete_another_teachers_student(): void
    {
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();

        $this->actingAs($teacherA, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Student A',
                'email' => 'studenta@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'studenta@example.com')->firstOrFail();

        $this->actingAs($teacherB, 'sanctum')
            ->deleteJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $student->id]);
    }

    public function test_student_created_by_assistant_is_visible_to_teacher(): void
    {
        $teacher = $this->makeTeacher();
        $assistant = $this->createUserWithRole(UserRole::Assistant);
        $assistant->created_by = $teacher->id;
        $assistant->save();

        $this->actingAs($assistant, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Assistant Student',
                'email' => 'asststudent@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'asststudent@example.com')->firstOrFail();

        // Teacher can see this student in list
        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students')
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($student->id, $ids);

        // Teacher can manage/view this student
        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200);
    }

    public function test_suspended_student_can_be_returned_to_active(): void
    {
        $teacher = $this->makeTeacher();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', [
                'name' => 'Suspended Student',
                'email' => 'suspended@example.com',
                'password' => 'secret123',
            ])->assertStatus(201);

        $student = User::where('email', 'suspended@example.com')->firstOrFail();

        // Suspend the student via renew endpoint
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/renew", [
                'decision' => 'suspend',
            ])->assertStatus(200)
            ->assertJsonPath('data.access_status', 'suspended')
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($student->fresh()->isActive());
        $this->assertEquals('suspended', $student->fresh()->accessStatus());

        // Return the student back to active via renew endpoint (keep_active)
        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/renew", [
                'decision' => 'keep_active',
                'months' => 1,
            ])->assertStatus(200)
            ->assertJsonPath('data.access_status', 'active')
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($student->fresh()->isActive());
        $this->assertEquals('active', $student->fresh()->accessStatus());

        // Suspend again via deactivate
        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/teacher/students/{$student->id}/deactivate")
            ->assertStatus(200)
            ->assertJsonPath('data.access_status', 'suspended')
            ->assertJsonPath('data.is_active', false);

        // Reactivate via activate endpoint
        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/teacher/students/{$student->id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.access_status', 'active')
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($student->fresh()->isActive());
        $this->assertEquals('active', $student->fresh()->accessStatus());
    }
}
