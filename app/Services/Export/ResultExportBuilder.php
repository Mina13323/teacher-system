<?php

namespace App\Services\Export;

use App\Enums\ExamAttemptStatus;
use App\Models\Exam;

/**
 * Builds result-export payloads (CSV / XLSX / PDF) from exam attempts.
 *
 * Shared by the synchronous export endpoint (small sheets) and
 * {@see \App\Jobs\GenerateResultExportJob} (queued large sheets) so both
 * paths are byte-identical and only ever authorized upstream.
 */
final class ResultExportBuilder
{
    /**
     * @return list<string>
     */
    public function columnHeaders(): array
    {
        return [
            'attempt_id', 'student_id', 'student_name', 'student_code', 'email',
            'status', 'outcome', 'score', 'percentage', 'passed',
            'started_at', 'submitted_at', 'grades_published_at',
            'end_reason', 'integrity_status', 'violation_warnings',
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string|null>>
     */
    public function rows(Exam $exam)
    {
        return $exam->attempts()
            ->with(['student'])
            ->orderBy('student_id')
            ->orderBy('id')
            ->get()
            ->map(fn ($attempt) => $this->rowFor($attempt))
            ->values();
    }

    /**
     * @param  iterable<int, array<string|null>>  $rows
     */
    public function csv(iterable $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $this->columnHeaders(), ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @param  iterable<int, array<string|null>>  $rows
     */
    public function xlsx(iterable $rows): string
    {
        $xlsx = new XlsxWriter('Results');
        $xlsx->addRow($this->columnHeaders());
        $xlsx->setHeaderRow(1);
        foreach ($rows as $row) {
            $xlsx->addRow($row);
        }

        return $xlsx->output();
    }

    /**
     * @param  iterable<int, array<string|null>>  $rows
     */
    public function pdf(Exam $exam, iterable $rows): string
    {
        $font = TrueTypeFont::load(base_path('resources/fonts/DejaVuSans.ttf'));
        $pdf = new SimplePdfWriter($font, true);

        $headers = $this->columnHeaders();
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

    /**
     * @return list<string|null>
     */
    public function rowFor(\App\Models\ExamAttempt $attempt): array
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
}
