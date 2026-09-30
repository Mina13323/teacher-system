<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Actions\Auth\CreateStudentAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bulk student import from CSV (P1): validate -> preview -> confirm -> report.
 *
 * Design notes:
 *  - Stateless and idempotent-ish: `preview` never writes; `confirm` re-parses
 *    and re-validates the same CSV, creates only valid rows, skips duplicates,
 *    and reports {imported, skipped, failed} per row.
 *  - Duplicates: identity collisions (same email, same phone, or same
 *    student_code — in the file or already in the system) are SKIPPED, never
 *    overwritten: import can never mutate existing accounts. Same NAME alone
 *    is not a duplicate — namesakes are normal in a school.
 *  - Credentials are generated server-side by the canonical
 *    CreateStudentAction (hashed storage); plaintext temporary passwords are
 *    returned ONCE in the confirm response so staff can distribute them, and
 *    are never persisted or audited.
 *
 * CSV columns (header row optional; order-sensitive when headerless):
 *   name, email, phone, student_code, academic_year, academic_subject
 */
class BulkStudentImportController extends Controller
{
    private const COLUMNS = ['name', 'email', 'phone', 'student_code', 'academic_year', 'academic_subject'];

    public function __construct(
        private readonly CreateStudentAction $createStudent,
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $rows = $this->parseAndValidate($request);

        return $this->success([
            'preview' => $rows,
            'summary' => $this->summarize($rows),
        ], 'Import preview generated. No students were created.');
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->authorizeImport($request);

        $rows = $this->parseAndValidate($request);

        $imported = [];
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if ($row['status'] === 'duplicate') {
                $skipped++;
                continue;
            }

            if ($row['status'] !== 'valid') {
                $failed++;
                continue;
            }

            $data = array_filter([
                'name' => $row['name'],
                'email' => $row['email'] ?: null,
                'phone' => $row['phone'] ?: null,
                'student_code' => $row['student_code'] ?: null,
                'academic_year' => $row['academic_year'] ?: null,
                'academic_subject' => $row['academic_subject'] ?: null,
            ], fn ($v) => $v !== null);

            try {
                $student = $this->createStudent->execute($request->user(), $data);
                $imported[] = $student->generated_credentials;
            } catch (\Throwable $e) {
                $failed++;
                logger()->warning('bulk import row failed', ['row' => $row['line'], 'error' => $e->getMessage()]);
            }
        }

        $report = [
            'imported' => count($imported),
            'skipped_duplicates' => $skipped,
            'failed' => $failed,
            'total_rows' => count($rows),
        ];

        $this->auditLog->execute('student.import', null, [
            'imported' => (string) $report['imported'],
            'skipped' => (string) $skipped,
            'failed' => (string) $failed,
            'total_rows' => (string) $report['total_rows'],
        ]);

        return $this->success([
            'report' => $report,
            // One-time credentials for staff to distribute; never persisted.
            'credentials' => $imported,
        ], 'Bulk import completed.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseAndValidate(Request $request): array
    {
        $data = $request->validate([
            'csv' => ['required', 'string', 'max:512000'],
        ]);

        $lines = preg_split('/\r\n|\r|\n/', trim($data['csv']));
        $lines = array_values(array_filter($lines, fn ($l) => trim((string) $l) !== ''));

        if (count($lines) > 1000) {
            abort(422, 'A single import is limited to 1000 rows.');
        }

        // Optional header row: only consumed when it actually looks like one —
        // a headerless file's first student must never be dropped.
        $first = array_map('trim', str_getcsv((string) $lines[0]));
        $hasHeader = isset($first[0]) && mb_strtolower($first[0]) === 'name';
        if ($hasHeader) {
            array_shift($lines);
        }

        $rows = [];
        $seenEmails = [];
        $seenPhones = [];
        $seenCodes = [];

        foreach ($lines as $i => $line) {
            $cells = array_map('trim', str_getcsv((string) $line));
            $row = [
                'line' => $i + ($hasHeader ? 2 : 1),
                'name' => $cells[0] ?? '',
                'email' => mb_strtolower($cells[1] ?? ''),
                'phone' => $cells[2] ?? '',
                'student_code' => $cells[3] ?? '',
                'academic_year' => $cells[4] ?? '',
                'academic_subject' => $cells[5] ?? '',
            ];

            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'string', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'student_code' => ['nullable', 'string', 'max:50'],
                'academic_year' => ['nullable', 'string', Rule::in(array_column(\App\Enums\AcademicYear::cases(), 'value'))],
                'academic_subject' => ['nullable', 'string', Rule::in(array_column(\App\Enums\AcademicSubject::cases(), 'value'))],
            ]);

            if ($validator->fails()) {
                $row['status'] = 'invalid';
                $row['errors'] = $validator->errors()->all();
                $rows[] = $row;
                continue;
            }

            // Identity-collision detection: within the file, then against users.
            $isDup = false;

            if ($row['email'] !== '' && (isset($seenEmails[$row['email']]) || User::where('email', $row['email'])->exists())) {
                $isDup = true;
                $row['errors'][] = 'email already in use';
            }
            if ($row['phone'] !== '' && (isset($seenPhones[$row['phone']]) || User::where('phone', $row['phone'])->exists())) {
                $isDup = true;
                $row['errors'][] = 'phone already in use';
            }
            if ($row['student_code'] !== '' && (isset($seenCodes[$row['student_code']]) || User::where('student_code', $row['student_code'])->exists())) {
                $isDup = true;
                $row['errors'][] = 'student_code already in use';
            }

            if ($row['email'] !== '') {
                $seenEmails[$row['email']] = true;
            }
            if ($row['phone'] !== '') {
                $seenPhones[$row['phone']] = true;
            }
            if ($row['student_code'] !== '') {
                $seenCodes[$row['student_code']] = true;
            }

            $row['status'] = $isDup ? 'duplicate' : 'valid';
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{valid: int, duplicate: int, invalid: int, total: int}
     */
    private function summarize(array $rows): array
    {
        return [
            'valid' => count(array_filter($rows, fn ($r) => $r['status'] === 'valid')),
            'duplicate' => count(array_filter($rows, fn ($r) => $r['status'] === 'duplicate')),
            'invalid' => count(array_filter($rows, fn ($r) => $r['status'] === 'invalid')),
            'total' => count($rows),
        ];
    }

    private function authorizeImport(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->isTeacher() || $user->isAssistant() || $user->isAdmin()),
            403,
            'Not authorized to import students.'
        );
    }
}
