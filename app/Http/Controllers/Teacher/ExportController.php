<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-authorized result exports for one exam (P1).
 *
 * Every export is scoped to a single exam the requester may view attempts for
 * (`viewAttempts` policy) — exports can never widen data visibility. Each run
 * is written to the audit trail with the row count.
 *
 * `csv` streams directly (memory-safe) and is the canonical machine format.
 * `print` renders a self-contained print-friendly HTML sheet (use the
 * browser's Print -> PDF for a PDF copy) — a deliberate no-dependency choice:
 * the project ships no PDF library and adding one is a deployment decision.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function results(Request $request, Exam $exam)
    {
        $this->authorize('viewAttempts', $exam);

        $format = $request->string('format', 'csv')->toString();

        $rows = $exam->attempts()
            ->with(['student'])
            ->orderBy('student_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($attempt) => $this->rowFor($attempt))
            ->values();

        $this->auditLog->execute('results.export', $exam, [
            'format' => $format,
            'rows' => (string) $rows->count(),
        ]);

        if ($format === 'print') {
            return response($this->printableHtml($exam, $rows))
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'attempt_id', 'student_id', 'student_name', 'student_code', 'email',
                'status', 'outcome', 'score', 'percentage', 'passed',
                'started_at', 'submitted_at', 'grades_published_at',
                'end_reason', 'integrity_status', 'violation_warnings',
            ], ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }

            fclose($out);
        }, 'exam-'.$exam->id.'-results.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * @return list<string|null>
     */
    private function rowFor(\App\Models\ExamAttempt $attempt): array
    {
        $outcome = $attempt->outcome();
        $definitive = in_array($outcome->value, ['passed', 'failed'], true);

        return [
            $attempt->id,
            $attempt->student_id,
            $attempt->student?->name,
            $attempt->student?->student_code,
            $attempt->student?->email,
            $attempt->status instanceof ExamAttemptStatus ? $attempt->status->value : (string) $attempt->status,
            $outcome->value,
            $attempt->score,
            $attempt->percentage,
            $definitive ? ($outcome->value === 'passed' ? 'true' : 'false') : '',
            $attempt->started_at?->toISOString(),
            $attempt->submitted_at?->toISOString(),
            $attempt->grades_published_at?->toISOString(),
            $attempt->end_reason,
            $attempt->integrity_status?->value,
            (int) $attempt->violation_warnings,
        ];
    }

    /**
     * @param  iterable<int, array<string|null>>  $rows
     */
    private function printableHtml(Exam $exam, $rows): string
    {
        $esc = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($row as $cell) {
                $body .= '<td>'.$esc($cell).'</td>';
            }
            $body .= '</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Results — '.$esc($exam->title).'</title>'
            .'<style>body{font-family:system-ui,sans-serif;margin:2rem}h1{font-size:1.2rem}'
            .'table{border-collapse:collapse;width:100%;font-size:12px}'
            .'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
            .'th{background:#f4f4f4}@media print{body{margin:0.5in}}</style></head><body>'
            .'<h1>Exam results — '.$esc($exam->title).'</h1>'
            .'<p>Generated: '.now()->toDateTimeString().' (print this page to save a PDF)</p>'
            .'<table><thead><tr><th>Attempt</th><th>Student</th><th>Code</th><th>Email</th><th>Status</th>'
            .'<th>Outcome</th><th>Score</th><th>%</th><th>Passed</th><th>Started</th><th>Submitted</th>'
            .'<th>Published</th><th>End reason</th><th>Integrity</th><th>Warnings</th></tr></thead><tbody>'
            .$body.'</tbody></table></body></html>';
    }
}
