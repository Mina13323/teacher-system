<?php

namespace App\Console\Commands;

use App\Actions\Exam\FinalizeExpiredAttemptAction;
use App\Enums\ExamAttemptStatus;
use App\Models\ExamAttempt;
use Illuminate\Console\Command;

/**
 * Server-side exam lifecycle: finalizes every in-progress attempt whose
 * deadline has passed, according to each exam's expiry policy
 * (auto-submit + grade saved answers / legacy 'expire').
 *
 * The system no longer waits for "the next student request" to close an
 * attempt: this runs on the scheduler (every minute) so a dead battery,
 * network loss or closed laptop can never leave an attempt dangling — and the
 * student's saved answers still get graded at the deadline.
 *
 * Idempotent: FinalizeExpiredAttemptAction only touches in-progress rows and
 * is transactional per attempt, so running it twice (or concurrently with a
 * lazy touch from the student's heartbeat) can never double-grade, duplicate
 * answers or duplicate notifications. A failure on one attempt is logged and
 * does not stop the rest.
 */
class ProcessExpiredAttemptsCommand extends Command
{
    protected $signature = 'attempts:process-expired {--limit=200 : Maximum attempts to process this run}';

    protected $description = 'Auto-submit/expire exam attempts whose deadline has passed (idempotent).';

    public function handle(FinalizeExpiredAttemptAction $finalizeExpired): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $processed = 0;
        $failed = 0;

        // Three narrow reads instead of one OR across them: each uses the
        // (status, expires_at) index on in-progress rows, so the per-minute
        // sweep no longer scans every historical attempt during an exam. The
        // union is the same set the single query selected.
        $inProgress = fn () => ExamAttempt::query()->where('exam_attempts.status', ExamAttemptStatus::InProgress->value);

        $ids = $inProgress()
            ->where('exam_attempts.expires_at', '<=', now())
            ->orderBy('exam_attempts.id')
            ->limit($limit)
            ->pluck('exam_attempts.id')
            // Legacy rows without a stored deadline (the column is NOT NULL
            // today, so this is normally empty).
            ->merge($inProgress()
                ->whereNull('exam_attempts.expires_at')
                ->whereNotNull('exam_attempts.started_at')
                ->orderBy('exam_attempts.id')
                ->limit($limit)
                ->pluck('exam_attempts.id'))
            // The exam window closed before the attempt's own deadline
            // (soft-deleted exams included, as in the attempt's exam relation).
            ->merge($inProgress()
                ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                ->whereNotNull('exams.ends_at')
                ->where('exams.ends_at', '<=', now())
                ->orderBy('exam_attempts.id')
                ->limit($limit)
                ->pluck('exam_attempts.id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->take($limit)
            ->values();

        ExamAttempt::query()
            ->with('exam')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->each(function (ExamAttempt $attempt) use ($finalizeExpired, &$processed, &$failed) {
                try {
                    if ($attempt->expires_at === null && $attempt->started_at !== null && $attempt->exam !== null) {
                        $computedExpiry = $attempt->exam->calculateAttemptExpiry($attempt->started_at);
                        if ($computedExpiry && $computedExpiry->isPast()) {
                            $attempt->expires_at = $computedExpiry;
                            $attempt->save();
                        }
                    }

                    if ($attempt->isExpired()) {
                        $finalizeExpired->execute($attempt);
                        $processed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("Attempt {$attempt->getKey()}: {$e->getMessage()}");
                    logger()->error('attempts:process-expired failed for attempt '.$attempt->getKey(), [
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        $this->info("Processed {$processed} expired attempt(s)".($failed ? ", {$failed} failed" : '').'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
