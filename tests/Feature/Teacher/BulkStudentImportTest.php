<?php

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\ApiTestCase;

/**
 * P1 — Bulk student import: CSV -> validate -> preview -> confirm -> report.
 *
 * Guarantees under test: preview never writes; confirm imports valid rows,
 * SKIPS identity duplicates (never overwrites), reports failed rows; passwords
 * are generated server-side, stored hashed, returned once; audited; teacher
 * authorization required.
 */
class BulkStudentImportTest extends ApiTestCase
{
    private string $csv = <<<CSV
name,email,phone
Sara Adel,sara.adel@example.com,0111111111
Omar Fouad,omar.fouad@example.com,0122222222
Bad Email,not-an-email,0133333333
,No Name Here,0144444444
Phone Twin,dup.in.file@example.com,0111111111
CSV;

    private function importAs($user, string $endpoint, string $csv)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/teacher/students/import/{$endpoint}", ['csv' => $csv]);
    }

    public function test_preview_validates_without_writing(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $before = User::count();

        $res = $this->importAs($teacher, 'preview', $this->csv)->assertStatus(200);

        $summary = $res->json('data.summary');
        $this->assertSame(2, $summary['valid']);
        $this->assertSame(2, $summary['invalid']);
        $this->assertSame(1, $summary['duplicate']);
        $this->assertSame(5, $summary['total']);

        $this->assertSame($before, User::count(), 'Preview must never create accounts');

        $rows = collect($res->json('data.preview'));
        $bad = $rows->firstWhere('line', 4);
        $this->assertSame('invalid', $bad['status']);
        $this->assertNotEmpty($bad['errors']);
    }

    public function test_confirm_imports_valid_skips_duplicates_and_reports(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $res = $this->importAs($teacher, 'confirm', $this->csv)->assertStatus(200);

        $report = $res->json('data.report');
        $this->assertSame(2, $report['imported']);
        $this->assertSame(1, $report['skipped_duplicates']);
        $this->assertSame(2, $report['failed']);
        $this->assertSame(5, $report['total_rows']);

        $this->assertNotNull(User::where('email', 'sara.adel@example.com')->first());
        $this->assertNotNull(User::where('email', 'omar.fouad@example.com')->first());
        $this->assertNull(User::where('email', 'not-an-email')->first());
        $this->assertNull(User::where('email', 'dup.in.file@example.com')->first());

        // Re-running the same CSV is a no-op report: nothing new, nothing broken.
        $second = $this->importAs($teacher, 'confirm', $this->csv)->assertStatus(200);
        $this->assertSame(0, $second->json('data.report.imported'));
        $this->assertSame(3, $second->json('data.report.skipped_duplicates'));
    }

    public function test_credentials_are_generated_server_side_stored_hashed_and_returned_once(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $res = $this->importAs($teacher, 'confirm', "Name Only,only.one@example.com,0199999999\n")
            ->assertStatus(200);

        $creds = collect($res->json('data.credentials'));
        $this->assertCount(1, $creds);
        $cred = $creds->first();
        $this->assertNotEmpty($cred['temporary_password']);
        $this->assertNotEmpty($cred['student_code']);
        $this->assertSame('only.one@example.com', $cred['login']);

        $user = User::where('email', 'only.one@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($cred['temporary_password'], $user->password), 'Temporary password must be the one that was hashed');
        $this->assertNotContains($cred['temporary_password'], [$user->password], 'Plaintext must never be stored');
        $this->assertTrue($user->must_change_password);

        // The audit trail must never contain the plaintext password.
        $log = AuditLog::query()->where('action', 'student.import')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($cred['temporary_password'], json_encode($log->metadata));
        $this->assertSame('1', $log->metadata['imported']);
    }

    public function test_import_requires_teacher_authorization(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $this->importAs($student, 'preview', "A,a@example.com,")->assertStatus(403);
        $this->importAs($student, 'confirm', "A,a@example.com,")->assertStatus(403);
    }

    public function test_existing_accounts_are_never_modified_by_import(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $existing = $this->createUserWithRole(UserRole::Student, [
            'name' => 'Existing Kid',
            'email' => 'existing.kid@example.com',
        ]);
        $originalName = $existing->name;

        $res = $this->importAs($teacher, 'confirm', "Renamed Kid,existing.kid@example.com,0177777777\n")
            ->assertStatus(200);

        $this->assertSame(0, $res->json('data.report.imported'));
        $this->assertSame(1, $res->json('data.report.skipped_duplicates'));

        $existing->refresh();
        $this->assertSame($originalName, $existing->name);
        $this->assertSame('existing.kid@example.com', $existing->email);
    }
}
