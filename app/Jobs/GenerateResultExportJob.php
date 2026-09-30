<?php

namespace App\Jobs;

use App\Actions\Audit\RecordAuditLogAction;
use App\Models\Exam;
use App\Models\Export;
use App\Services\Export\ResultExportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * PHASE 2 §25/§24 — Generate a large result export off the request cycle.
 *
 * Idempotent: the export row is a state machine (queued → running → done /
 * failed) and re-running a done export returns its existing file. Unique per
 * export id so a retry storm can never generate duplicates. The file lands on
 * the private `local` disk — only the authorization-checked download endpoint
 * serves it.
 */
class GenerateResultExportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(private readonly int $exportId)
    {
    }

    public function uniqueId(): string
    {
        return 'export:'.$this->exportId;
    }

    public function handle(ResultExportBuilder $builder, RecordAuditLogAction $auditLog): void
    {
        $export = Export::query()->find($this->exportId);
        if ($export === null) {
            return;
        }
        if ($export->status === 'done') {
            return; // idempotent re-run
        }

        $export->status = 'running';
        $export->save();

        try {
            $exam = Exam::query()->findOrFail($export->subject_id);
            $rows = $builder->rows($exam);

            $payload = match ($export->format) {
                'xlsx' => $builder->xlsx($rows),
                'pdf' => $builder->pdf($exam, $rows),
                default => $builder->csv($rows),
            };

            $path = 'exports/'.$export->kind.'-'.$exam->getKey().'-'.$export->id.'.'.$export->format;
            Storage::disk('local')->put($path, $payload);

            $export->file_path = $path;
            $export->row_count = $rows->count();
            $export->status = 'done';
            $export->finished_at = now();
            $export->save();

            $auditLog->execute('results.export', $exam, [
                'format' => $export->format,
                'rows' => (string) $rows->count(),
                'queued' => 'true',
                'export_id' => (string) $export->id,
            ]);
        } catch (\Throwable $e) {
            $export->status = 'failed';
            $export->error = substr($e->getMessage(), 0, 500);
            $export->finished_at = now();
            $export->save();

            throw $e;
        }
    }
}
