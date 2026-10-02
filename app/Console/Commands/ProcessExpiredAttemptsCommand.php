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

        ExamAttempt::query()
            ->where('status', ExamAttemptStatus::InProgress->value)
            ->where('expires_at', '<', now())
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (ExamAttempt $attempt) use ($finalizeExpired, &$processed, &$failed) {
                try {
                    $finalizeExpired->execute($attempt);
                    $processed++;
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
