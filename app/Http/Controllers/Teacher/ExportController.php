<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Enums\ExamAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Services\Export\SimplePdfWriter;
use App\Services\Export\TrueTypeFont;
use App\Services\Export\XlsxWriter;
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

        if ($format === 'xlsx') {
            $xlsx = $this->resultsXlsx($exam, $rows);

            return response($xlsx)
                ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->header('Content-Disposition', 'attachment; filename="exam-'.$exam->id.'-results.xlsx"');
        }

        if ($format === 'pdf') {
            $pdf = $this->resultsPdf($exam, $rows);

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
     * @return list<string>
     */
    private function columnHeaders(): array
    {
        return [
            'attempt_id', 'student_id', 'student_name', 'student_code', 'email',
            'status', 'outcome', 'score', 'percentage', 'passed',
            'started_at', 'submitted_at', 'grades_published_at',
            'end_reason', 'integrity_status', 'violation_warnings',
        ];
    }

    /**
     * @param  iterable<int, array<string|null>>  $rows
     */
    private function resultsXlsx(Exam $exam, $rows): string
    {
        $xlsx = new XlsxWriter('Results');
        $headers = $this->columnHeaders();
        $xlsx->addRow($headers);
        $xlsx->setHeaderRow(1);
        foreach ($rows as $row) {
            $xlsx->addRow($row);
        }

        return $xlsx->output();
    }

    /**
     * @param  iterable<int, array<string|null>>  $rows
     */
    private function resultsPdf(Exam $exam, $rows): string
    {
        $font = TrueTypeFont::load(base_path('resources/fonts/DejaVuSans.ttf'));
        $pdf = new SimplePdfWriter($font, true);

        $headers = $this->columnHeaders();
        // Column widths (points) sized for A4 landscape; ids/dates are compact.
        $widths = [38, 40, 112, 58, 118, 52, 62, 34, 38, 32, 76, 76, 76, 62, 56, 34];

        $margin = 24.0;
        $pageW = $pdf->width() - 2 * $margin;
        $scale = $pageW / array_sum($widths);
        $widths = array_map(fn ($w) => $w * $scale, $widths);

        $drawHeader = function () use ($pdf, $headers, $widths, $margin, $exam) {
            $pdf->text($margin, 28, 'Exam results — '.$exam->title, 13, '#5B3A21');
            $pdf->text($margin, 46, 'Generated: '.now()->toDateTimeString(), 8, '#8a8a8a');
            $x = $margin;
            $pdf->rect($margin, 58, array_sum($widths), 18, '#EDE6DC');
            foreach ($headers as $i => $h) {
                $pdf->text($x + 2, 70, $this->clipCell($pdf, $h, $widths[$i], 7), 7, '#3a2a1a');
                $x += $widths[$i];
            }
        };

        $drawHeader();
        $y = 88;
        $rowH = 16;
        $pageH = $pdf->height() - 24;
        $alt = false;
        foreach ($rows as $row) {
            if ($y + $rowH > $pageH) {
                $pdf->addPage();
                $drawHeader();
                $y = 88;
            }
            if ($alt) {
                $pdf->rect($margin, $y - 8, array_sum($widths), $rowH, '#FAF7F2');
            }
            $alt = ! $alt;
            $x = $margin;
            foreach ($row as $i => $cell) {
                $pdf->text($x + 2, $y + 3, $this->clipCell($pdf, (string) ($cell ?? ''), $widths[$i], 7), 7, '#222222');
                $x += $widths[$i];
            }
            $y += $rowH;
        }

        return $pdf->output();
    }

    private function clipCell(SimplePdfWriter $pdf, string $text, float $width, float $size): string
    {
        if ($pdf->measure($text, $size) <= $width - 4) {
            return $text;
        }
        while ($text !== '' && $pdf->measure($text.'…', $size) > $width - 2) {
            $text = mb_substr($text, 0, -1, 'UTF-8');
        }

        return $text === '' ? '' : $text.'…';
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
