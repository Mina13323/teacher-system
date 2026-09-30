<?php

namespace App\Services\Observability;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Export;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 5 §40-41 — Operational metrics, derived from the authoritative
 * database (no drifting in-memory counters). Aggregates only: no student
 * content, no answer keys, no PII beyond what operators already see.
 *
 * Every figure answers a concrete ops question — above all §40's:
 * "Why did this student's exam terminate?" is answerable from
 * `termination_reasons` + the audit trail + end_reason on the attempt row.
 */
final class MetricsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $attemptScope = ExamAttempt::query();

        return [
            'generated_at' => now()->toISOString(),

            // Attempts lifecycle (§41)
            'attempts' => [
                'active' => (clone $attemptScope)->where('status', 'in_progress')->count(),
                'started_total' => (clone $attemptScope)->count(),
                'submitted_total' => (clone $attemptScope)->whereIn('status', ['submitted', 'grading', 'published'])->count(),
                'auto_submitted' => (clone $attemptScope)->where('end_reason', 'auto_submit_at_deadline')->count(),
                'expired' => (clone $attemptScope)->where('end_reason', 'expired')->count(),
            ],

            // Integrity (§41)
            'integrity' => [
                'flagged' => (clone $attemptScope)->where('integrity_status', 'flagged')->count(),
                'resumed_by_teacher' => (clone $attemptScope)->whereNotNull('resumed_at')->count(),
                'disqualified' => (clone $attemptScope)
                    ->where('integrity_status', 'flagged')
                    ->whereNotNull('grades_published_at')
                    ->count(),
            ],

            // §40 — termination reasons: the "why did it end" answer.
            'termination_reasons' => (clone $attemptScope)
                ->whereNotNull('end_reason')
                ->select('end_reason', DB::raw('count(*) as total'))
                ->groupBy('end_reason')
                ->pluck('total', 'end_reason'),

            // Interruption signals. Network loss is NEVER an integrity event
            // (locked fairness model) — it is observable only as client-side
            // recovery + heartbeat gaps in request logs (X-Request-Id). What we
            // CAN count honestly here is confirmed-violation warnings and
            // threshold terminations.
            'warning_bearing_attempts' => (clone $attemptScope)->where('violation_warnings', '>', 0)->count(),
            'threshold_terminations' => DB::table('exam_integrity_events')
                ->where('event_type', 'threshold_termination')
                ->count(),

            // Grading queue
            'pending_essay_grading' => (clone $attemptScope)
                ->where('status', 'grading')
                ->whereHas('answers', fn ($q) => $q->whereNull('graded_at'))
                ->count(),

            // Exports (§25 observability)
            'exports' => [
                'queued' => Export::query()->where('status', 'queued')->count(),
                'running' => Export::query()->where('status', 'running')->count(),
                'failed' => Export::query()->where('status', 'failed')->count(),
                'done' => Export::query()->where('status', 'done')->count(),
            ],

            // Notification/audit activity (delivery-rate proxy)
            'audit_events_24h' => AuditLog::query()->where('created_at', '>=', now()->subDay())->count(),
            'notifications_24h' => DB::table('notifications')
                ->where('created_at', '>=', now()->subDay())
                ->count(),

            // Queue health (failed jobs is the dead-letter signal)
            'failed_jobs' => DB::table('failed_jobs')->count(),

            'exams' => [
                'total' => Exam::query()->count(),
                'published' => Exam::query()->where('status', 'published')->count(),
            ],
        ];
    }
}
