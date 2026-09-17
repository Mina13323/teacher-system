<?php

namespace Tests\Feature\Student;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\StudentCredentialService;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\Feature\ApiTestCase;

/**
 * Temporary credential security.
 *
 * The old scheme derived the password from {student_code}{academic_year} —
 * values printed on the credential card itself — which made the password
 * reconstructible by anyone who could read the card. These tests lock in the
 * replacement: a short, simple password drawn from a CSPRNG, stored only as a
 * hash, revealed exactly once, and never recomputed by any client.
 */
class TemporaryCredentialSecurityTest extends ApiTestCase
{
    private function teacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function createStudent(User $teacher, array $payload = []): array
    {
        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', array_merge([
                'name' => 'Temp Credential Student',
            ], $payload))
            ->assertStatus(201);

        return [$response, $response->json('credentials.temporary_password')];
    }

    // -----------------------------------------------------------------
    // 1–4. The generated password itself.
    // -----------------------------------------------------------------

    public function test_new_student_receives_a_temporary_password(): void
    {
        [, $password] = $this->createStudent($this->teacher());

        $this->assertIsString($password);
        $this->assertNotEmpty($password);
    }

    public function test_temporary_password_matches_the_simple_expected_format(): void
    {
        [, $password] = $this->createStudent($this->teacher());

        $this->assertMatchesRegularExpression(StudentCredentialService::TEMPORARY_PASSWORD_REGEX, $password);

        // Simple and short enough to read off a card and type on a phone.
        $this->assertLessThanOrEqual(12, strlen($password));
    }

    /**
     * The regression guard. The generator must not return the old value, and —
     * more importantly — it must not be *able* to, because it receives no
     * student data at all.
     */
    public function test_password_is_not_derived_from_student_code_and_year(): void
    {
        [$response, $password] = $this->createStudent($this->teacher());

        $studentCode = $response->json('data.student_code');

        $this->assertNotSame($studentCode.'2026', $password);
        $this->assertNotSame($studentCode.date('Y'), $password);
        $this->assertStringNotContainsString($studentCode, $password);
        $this->assertStringNotContainsString('2026', $password);
    }

    /**
     * Structural proof rather than a statistical one: with zero parameters the
     * generator cannot read the student code, name, year, phone, email or id.
     */
    public function test_generator_accepts_no_student_data_at_all(): void
    {
        $method = new ReflectionMethod(StudentCredentialService::class, 'generateTemporaryPassword');

        $this->assertSame(0, $method->getNumberOfParameters());
        $this->assertSame(0, $method->getNumberOfRequiredParameters());
    }

    /**
     * Diversity across a batch. Deliberately loose so it cannot flake: 100
     * draws from a 100,000-value space should yield ~100 distinct values, so
     * requiring 90 leaves no realistic chance of a random CI failure while
     * still failing hard against any deterministic generator (which would
     * yield 1).
     */
    public function test_generated_passwords_are_random_not_repeating(): void
    {
        $service = new StudentCredentialService;

        $passwords = [];
        for ($i = 0; $i < 100; $i++) {
            $password = $service->generateTemporaryPassword();
            $this->assertMatchesRegularExpression(StudentCredentialService::TEMPORARY_PASSWORD_REGEX, $password);
            $passwords[] = $password;
        }

        $this->assertGreaterThanOrEqual(90, count(array_unique($passwords)));
    }

    // -----------------------------------------------------------------
    // 5–6. Storage is hash-only.
    // -----------------------------------------------------------------

    public function test_password_is_stored_as_a_hash_and_verified_by_laravel(): void
    {
        [$response, $password] = $this->createStudent($this->teacher());
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();

        $this->assertTrue(Hash::check($password, $student->password));
    }

    public function test_plaintext_password_is_never_stored_in_the_database(): void
    {
        [$response, $password] = $this->createStudent($this->teacher());
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();

        $this->assertNotSame($password, $student->password);
        $this->assertStringStartsNotWith('ELM@', (string) $student->password);
        $this->assertStringNotContainsString($password, (string) $student->password);

        // No plaintext column may exist anywhere on the row.
        foreach ($student->getAttributes() as $column => $value) {
            $this->assertNotSame(
                $password,
                $value,
                "Plaintext temporary password found in column: {$column}"
            );
        }
    }

    // -----------------------------------------------------------------
    // 7–9. Reset behaviour.
    // -----------------------------------------------------------------

    public function test_explicit_reset_generates_a_new_hashed_password(): void
    {
        // One teacher owns both the creation and the reset; a fresh teacher
        // would not manage this student and would correctly get 403.
        $teacher = $this->teacher();
        [$response] = $this->createStudent($teacher);
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();
        $originalHash = $student->password;

        $reset = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(200);

        $newPassword = $reset->json('data.credentials.temporary_password');

        $this->assertMatchesRegularExpression(StudentCredentialService::TEMPORARY_PASSWORD_REGEX, $newPassword);
        // The stored hash changed, proving a real regeneration happened.
        $this->assertNotSame($originalHash, $student->fresh()->password);
        $this->assertTrue(Hash::check($newPassword, $student->fresh()->password));
    }

    public function test_reset_does_not_use_the_student_code_and_year_template(): void
    {
        $teacher = $this->teacher();
        [$response] = $this->createStudent($teacher);
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();

        $reset = $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(200);

        $newPassword = $reset->json('data.credentials.temporary_password');

        $this->assertNotSame($student->student_code.'2026', $newPassword);
        $this->assertStringNotContainsString($student->student_code, $newPassword);
    }

    /**
     * Only an explicit reset rotates the password. An ordinary profile update
     * must leave the existing hash untouched, so existing students are never
     * silently re-credentialed.
     */
    public function test_password_unchanged_unless_credentials_are_explicitly_reset(): void
    {
        $teacher = $this->teacher();
        [$response] = $this->createStudent($teacher);
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();
        $originalHash = $student->password;

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/students/{$student->id}", ['name' => 'Renamed Student'])
            ->assertStatus(200);

        $this->assertSame($originalHash, $student->fresh()->password);
    }

    /**
     * An existing account that was created under the old scheme keeps working
     * until staff explicitly resets it.
     */
    public function test_legacy_deterministic_password_remains_valid_until_reset(): void
    {
        $teacher = $this->teacher();
        $legacyPassword = 'ELM-10012026';

        $student = $this->createUserWithRole(UserRole::Student, [
            'created_by' => $teacher->id,
            'student_code' => 'ELM-1001',
            'password' => $legacyPassword,
            'must_change_password' => true,
        ]);

        $this->assertTrue(Hash::check($legacyPassword, $student->fresh()->password));

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(200);

        $rotated = $student->fresh();
        $this->assertFalse(Hash::check($legacyPassword, $rotated->password));
    }

    // -----------------------------------------------------------------
    // 10–13. No client may reconstruct a password.
    // -----------------------------------------------------------------

    /**
     * Executable static guard over the whole frontend. Catches the exact
     * `${student_code}2026` pattern that used to live in the print sheet, and
     * any future variant of it.
     */
    public function test_frontend_never_reconstructs_a_password_from_student_data(): void
    {
        $offenders = [];

        $files = $this->frontendFiles();
        $this->assertNotEmpty($files, 'Frontend source files were not found — guard is vacuous');

        $patterns = [
            // `${st.student_code}2026` — the exact pattern that used to live
            // in the print sheet.
            '/\$\{[^}]*student_code[^}]*\}\s*20\d\d/',
            // student_code + 2026 / '2026' / currentYear / new Date() / academicYear
            '/student_code[\'"\]\s]*\+\s*(?:[\'"]?20\d\d|currentYear|new\s+Date\(|academic_?[Yy]ear)/',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $offenders[] = basename($file).' matches '.$pattern;
                }
            }
        }

        $this->assertSame([], $offenders, "Frontend reconstructs a password:\n".implode("\n", $offenders));
    }

    /**
     * The print sheet must render the revealed password and fall back to an
     * "unavailable" notice — never a computed value.
     */
    public function test_print_sheet_consumes_the_revealed_password_only(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/components/students/PrintCredentialsSheet.vue'));

        $this->assertStringContainsString('st.revealed_password', $source);
        $this->assertStringContainsString('printPasswordUnavailable', $source);
        $this->assertStringNotContainsString('student_code}2026', $source);
    }

    /**
     * The WhatsApp flow may only consume an already-revealed payload: it must
     * not call the credential-reset endpoint, because that would rotate the
     * hash and invalidate the password the staff member is looking at.
     */
    public function test_whatsapp_flow_does_not_regenerate_credentials(): void
    {
        foreach ([
            'resources/js/components/students/WhatsAppContactModal.vue',
            'resources/js/composables/useWhatsApp.js',
            'resources/js/views/Teacher/Students.vue',
            'resources/js/views/Teacher/StudentShow.vue',
            'resources/js/views/Teacher/StudentForm.vue',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));

            // The composable/modal must be free of any credential call.
            if (str_contains($relative, 'useWhatsApp.js') || str_contains($relative, 'WhatsAppContactModal')) {
                $this->assertStringNotContainsString('resetStudentCredentials', $source, $relative.' regenerates credentials');
                $this->assertStringNotContainsString('reset-credentials', $source, $relative.' regenerates credentials');
            }

            // Nothing may build a password out of student data anywhere.
            $this->assertStringNotContainsString('student_code}2026', $source, $relative.' reconstructs a password');
        }
    }

    /**
     * No endpoint exists that hands back a password after the reveal.
     */
    public function test_no_endpoint_exposes_a_recoverable_password(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->map(fn ($route) => strtoupper(implode('|', $route->methods())).' '.$route->uri())
            ->values();

        $getters = $routes->filter(fn ($r) => str_starts_with($r, 'GET') && str_contains($r, 'password'));

        $this->assertCount(0, $getters, 'GET password route exists: '.$getters->implode(', '));
    }

    // -----------------------------------------------------------------
    // 14–15. Authorization.
    // -----------------------------------------------------------------

    public function test_student_cannot_reset_or_reveal_another_students_credentials(): void
    {
        $teacher = $this->teacher();
        [$response] = $this->createStudent($teacher);
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();
        $intruder = $this->createUserWithRole(UserRole::Student);

        $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(403);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(403);
    }

    public function test_assistant_has_the_same_credential_capability_as_teacher(): void
    {
        $teacher = $this->teacher();
        $assistant = $this->createUserWithRole(UserRole::Assistant, ['created_by' => $teacher->id]);

        [$response] = $this->createStudent($teacher);
        $student = User::where('student_code', $response->json('data.student_code'))->firstOrFail();

        $reset = $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/v1/teacher/students/{$student->id}/reset-credentials")
            ->assertStatus(200);

        $password = $reset->json('data.credentials.temporary_password');

        $this->assertMatchesRegularExpression(StudentCredentialService::TEMPORARY_PASSWORD_REGEX, $password);
        $this->assertTrue(Hash::check($password, $student->fresh()->password));
    }

    // -----------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function frontendFiles(): array
    {
        $root = base_path('resources/js');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['js', 'vue'], true)) {
                // The i18n catalogs are translation data, not credential logic.
                if (str_contains($file->getPathname(), 'i18n'.DIRECTORY_SEPARATOR.'messages')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
