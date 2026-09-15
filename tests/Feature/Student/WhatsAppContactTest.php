<?php

namespace Tests\Feature\Student;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\PhoneNumber;
use Tests\Feature\ApiTestCase;

/**
 * Phone data exposure for the WhatsApp contact workflow.
 *
 * The wa.me link itself is built client-side from an already-authorized
 * resource, so what must be proven here is the data boundary: who can see a
 * student's phone, that the number reaching the client is normalized exactly
 * once (server-side), and that no credential ever travels with it.
 */
class WhatsAppContactTest extends ApiTestCase
{
    private function teacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function studentOf(User $teacher, array $attributes = []): User
    {
        return $this->createUserWithRole(UserRole::Student, array_merge([
            'created_by' => $teacher->id,
            'phone' => '01012345678',
            'student_code' => 'ELM-1001',
        ], $attributes));
    }

    // -----------------------------------------------------------------
    // Who may see a student's phone number.
    // -----------------------------------------------------------------

    public function test_teacher_can_access_student_phone_and_whatsapp_number(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '+201012345678')
            ->assertJsonPath('data.whatsapp_phone', '201012345678');
    }

    /**
     * Assistant = Teacher operational parity: the same student-management data
     * is available, with no separate permission of its own.
     */
    public function test_assistant_has_the_same_phone_access_as_teacher(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);
        $assistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $teacher->id]);

        $this->actingAs($assistant, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '+201012345678')
            ->assertJsonPath('data.whatsapp_phone', '201012345678');
    }

    public function test_admin_can_access_student_phone(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);
        $admin = $this->createUserWithRole(UserRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.whatsapp_phone', '201012345678');
    }

    /**
     * A student may never reach another student's record through the staff
     * endpoint — the role middleware on the teacher group stops this before any
     * policy runs.
     */
    public function test_student_cannot_access_another_students_phone(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);
        $intruder = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(403);
    }

    /**
     * A student may see their own phone through their own profile, which is the
     * only phone they are entitled to.
     */
    public function test_student_sees_their_own_phone_on_their_own_profile(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/auth/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '+201012345678')
            ->assertJsonPath('data.whatsapp_phone', '201012345678');
    }

    // -----------------------------------------------------------------
    // Normalization happens once, on the server.
    // -----------------------------------------------------------------

    public function test_phone_is_normalized_to_e164_on_write(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher, ['phone' => null]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/students/{$student->id}", ['phone' => '01012345678'])
            ->assertStatus(200);

        $this->assertSame('+201012345678', $student->fresh()->phone);
    }

    public function test_already_normalized_number_is_not_double_prefixed(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher, ['phone' => null]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/students/{$student->id}", ['phone' => '+201012345678'])
            ->assertStatus(200);

        $this->assertSame('+201012345678', $student->fresh()->phone);
        $this->assertSame('201012345678', $student->fresh()->whatsappNumber());
    }

    public function test_invalid_phone_is_rejected_rather_than_stored(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher, ['phone' => '+201012345678']);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/students/{$student->id}", ['phone' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        // The previous valid number is untouched.
        $this->assertSame('+201012345678', $student->fresh()->phone);
    }

    /**
     * With no usable number there is no destination, so no link may be built.
     * The API signals this with a null rather than an empty or guessed value.
     */
    public function test_absent_or_invalid_phone_yields_no_whatsapp_number(): void
    {
        $teacher = $this->teacher();
        $noPhone = $this->studentOf($teacher, ['phone' => null, 'student_code' => 'ELM-1002']);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$noPhone->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.whatsapp_phone', null);

        $this->assertNull(PhoneNumber::toWhatsApp($noPhone->phone));
    }

    // -----------------------------------------------------------------
    // Credentials never travel with phone data.
    // -----------------------------------------------------------------

    public function test_student_resource_never_contains_a_password(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/students/{$student->id}")
            ->assertStatus(200);

        $data = $response->json('data');

        // No credential-bearing key. ('must_change_password' is a boolean
        // lifecycle flag and is deliberately not covered by this assertion.)
        foreach (['password', 'temporary_password', 'plain_password', 'api_token', 'token'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "StudentResource leaked key: {$forbidden}");
        }

        // Nor a credential value anywhere in the serialized payload.
        $payload = json_encode($data);

        $this->assertStringNotContainsString('ELM-10012026', (string) $payload, 'Temporary password leaked');
        $this->assertStringNotContainsString((string) $student->password, (string) $payload, 'Password hash leaked');
    }

    /**
     * Passwords are stored hashed only; the temporary password is derivable
     * from the student code by the existing credential service, so nothing may
     * ever write a plaintext copy to the users table.
     */
    public function test_password_is_stored_hashed_not_plaintext(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(200);

        $fresh = $student->fresh();

        $this->assertNotSame('ELM-10012026', $fresh->password);
        $this->assertStringStartsNotWith('ELM-', (string) $fresh->password);
    }

    /**
     * Regenerating credentials is authorized for staff only; a student cannot
     * invoke it on themselves or anyone else.
     */
    public function test_student_cannot_invoke_the_credential_reset_workflow(): void
    {
        $teacher = $this->teacher();
        $student = $this->studentOf($teacher);
        $intruder = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(403);
    }
}
