<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Jobs\GenerateResultExportJob;
use App\Models\Export;
use App\Services\Export\ResultExportBuilder;
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
 * `xlsx` emits a real Office Open XML workbook via the dependency-free
 * `XlsxWriter` (pure-PHP ZIP + SpreadsheetML, full Unicode).
 * `pdf` emits a real PDF via the dependency-free `SimplePdfWriter`, which
 * embeds `resources/fonts/DejaVuSans.ttf` and shapes Arabic text in-process —
 * no pdf lib, no network. `print` renders the print-friendly HTML sheet for
 * the browser's own Print -> PDF path (kept for compatibility).
 */
class ExportController extends Controller
{
    /** Exports above this many rows are queued instead of streamed (§24/§25). */
    private const SYNC_ROW_LIMIT = 500;

    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
        private readonly ResultExportBuilder $builder,
    ) {
    }

    public function results(Request $request, Exam $exam)
    {
        $this->authorize('viewAttempts', $exam);

        $format = $request->string('format', 'csv')->toString();

        $rows = $this->builder->rows($exam);

        // Large sheets are generated on the queue: the HTTP request returns
        // immediately with an export record to poll (never a long request).
        if ($format !== 'print' && $rows->count() > self::SYNC_ROW_LIMIT) {
            $export = Export::create([
                'requested_by' => $request->user()->getKey(),
                'kind' => 'results',
                'format' => $format,
                'subject_type' => Exam::class,
                'subject_id' => $exam->getKey(),
                'status' => 'queued',
            ]);

            GenerateResultExportJob::dispatch($export->getKey());

            return response()->json([
                'success' => true,
                'message' => 'Export queued — it will be ready shortly.',
                'data' => [
                    'export_id' => $export->getKey(),
                    'status' => 'queued',
                    'rows' => $rows->count(),
                ],
            ], 202);
        }

        $this->auditLog->execute('results.export', $exam, [
            'format' => $format,
            'rows' => (string) $rows->count(),
        ]);

        if ($format === 'xlsx') {
            $xlsx = $this->builder->xlsx($rows);

            return response($xlsx)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="exam-'.$exam->id.'-results.xlsx"');
        }

        if ($format === 'pdf') {
            $pdf = $this->builder->pdf($exam, $rows);

            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="exam-'.$exam->id.'-results.pdf"');
        }

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

    /** Status of a queued export (only the requester may see it). */
    public function show(Request $request, Export $export): \Illuminate\Http\JsonResponse
    {
        abort_unless($export->requested_by === $request->user()->getKey(), 403);

        return response()->json([
            'success' => true,
            'data' => [
                'export_id' => $export->getKey(),
                'status' => $export->status,
                'format' => $export->format,
                'rows' => $export->row_count,
                'error' => $export->error,
                'finished_at' => $export->finished_at?->toISOString(),
                'download_url' => $export->status === 'done'
                    ? "/api/v1/teacher/exports/{$export->getKey()}/download"
                    : null,
            ],
        ]);
    }

    /** Stream a finished export file (private disk, requester-only). */
    public function download(Request $request, Export $export)
    {
        abort_unless($export->requested_by === $request->user()->getKey(), 403);
        abort_unless($export->status === 'done' && $export->file_path, 404, 'Export not ready.');

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $export->file_path,
            'exam-export-'.$export->getKey().'.'.$export->format
        );
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
