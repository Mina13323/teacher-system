<?php

namespace App\Actions\Integrity;

use App\Actions\Exam\TerminateExamAttemptAction;
use App\Enums\IntegrityEventType;
use App\Enums\IntegritySeverity;
use App\Exceptions\InvalidAttemptStateException;
use App\Models\ExamAttempt;
use App\Models\ExamIntegrityEvent;
use App\Services\Integrity\IntegrityRiskConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records an integrity event reported by a student's client.
 *
 * The backend is authoritative:
 *  - only accepts browser-observable event types for an in-progress,
 *    non-expired attempt (explicit allowlist — derived/server-only types are
 *    rejected),
 *  - assigns severity and risk points server-side (never from the client),
 *  - gates an event against the attempt's FROZEN integrity settings: if the
 *    corresponding protection is disabled the event is recorded as "ignored"
 *    (0 risk) rather than inflating the score,
 *  - deduplicates repeated identical events within a short window so a
 *    malicious client cannot flood the score,
 *  - re-evaluates the attempt's risk score and integrity status afterwards.
 *
 * WARNING-BASED INTERRUPTION POLICY (fairness):
 *  - A violation never terminates the attempt on the first strike. Each
 *    counted violation (enabled, risk-bearing, non-deduplicated) increments
 *    the attempt's `violation_warnings` counter and the response reports
 *    {warning_count, warning_threshold} so the client can warn the student.
 *  - Only when warnings EXCEED the threshold (frozen per-attempt or config
 *    default) AND `terminate_on_violation` is enabled does the attempt end —
 *    via TerminateExamAttemptAction, which records ONE honest
 *    THRESHOLD_TERMINATION event with config-driven risk (never fabricated
 *    WINDOW_BLUR, never hardcoded points).
 *  - Heartbeat loss / network failure never reaches this action at all.
 *
 * @return array{
 *     event: ?ExamIntegrityEvent,
 *     deduplicated: bool,
 *     counted: bool,
 *     warning_count: int,
 *     warning_threshold: int,
 *     should_terminate: bool,
 *     terminated: bool,
 *     attempt: ExamAttempt
 * }
 */
class RecordIntegrityEventAction
{
    public function __construct(
        private readonly IntegrityRiskConfig $riskConfig,
        private readonly UpdateAttemptIntegrityStatusAction $updateStatus,
        private readonly TerminateExamAttemptAction $terminateAttempt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(ExamAttempt $attempt, IntegrityEventType $type, ?Carbon $occurredAt, array $metadata = []): array
    {
        // Defense in depth: the client may only submit browser-observable event
        // types. Derived conditions and server-only types are never accepted.
        if (! $type->isClientReportable()) {
            throw new InvalidAttemptStateException('This event type must be derived server-side.');
        }

        if (! $attempt->status->isInProgress() || $attempt->isExpired()) {
            throw new InvalidAttemptStateException('Integrity events can only be recorded for an active attempt.');
        }

        $occurredAt = $this->normalizeOccurredAt($attempt, $occurredAt);
        $attempt->loadMissing('integritySetting');

        $settingKey = $this->riskConfig->gateSetting($type);
        $enabled = $settingKey === null || $this->settingEnabled($attempt, $settingKey);

        // A repeat inside the deduplication window is answered without taking
        // the attempt lock (the common case for bursts of the same event). The
        // check is repeated under the lock below, so two concurrent identical
        // events still record at most one.
        $state = $this->isDuplicate($attempt, $type)
            ? ['event' => null, 'deduplicated' => true, 'counted' => false, 'attempt' => $attempt]
            : $this->recordLocked($attempt, $type, $occurredAt, $metadata, $enabled);

        $locked = $state['attempt'];
        $threshold = $this->warningThresholdFor($locked);
        $warningCount = (int) $locked->violation_warnings;

        // Threshold policy: warnings 1..N warn; the next counted violation
        // terminates — but only when the frozen settings say so.
        $terminateOnViolation = (bool) ($locked->integritySetting?->terminate_on_violation ?? true);
        $shouldTerminate = $terminateOnViolation && $warningCount > $threshold;

        $terminated = false;

        if ($shouldTerminate && $locked->status->isInProgress()) {
            $this->terminateAttempt->execute($locked, 'THRESHOLD_TERMINATION', [
                'warning_count' => $warningCount,
                'warning_threshold' => $threshold,
            ]);
            $terminated = true;
        }

        return [
            'event' => $state['event'],
            'deduplicated' => $state['deduplicated'],
            'counted' => $state['counted'],
            'warning_count' => $warningCount,
            'warning_threshold' => $threshold,
            'should_terminate' => $shouldTerminate,
            'terminated' => $terminated,
            'attempt' => $terminated ? $locked->fresh() : $locked,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{event: ?ExamIntegrityEvent, deduplicated: bool, counted: bool, attempt: ExamAttempt}
     */
    private function recordLocked(ExamAttempt $attempt, IntegrityEventType $type, Carbon $occurredAt, array $metadata, bool $enabled): array
    {
        return DB::transaction(function () use ($attempt, $type, $occurredAt, $metadata, $enabled) {
            // Lock the attempt so a concurrent submit cannot race a recording.
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            // The exam and the frozen settings were read for this attempt in
            // this request; only the attempt row needs the locked re-read.
            foreach (['exam', 'integritySetting'] as $relation) {
                if ($attempt->relationLoaded($relation)) {
                    $locked->setRelation($relation, $attempt->getRelation($relation));
                }
            }

            if (! $locked->status->isInProgress() || $locked->isExpired()) {
                throw new InvalidAttemptStateException('Integrity events can only be recorded for an active attempt.');
            }

            // Deduplicate repeated identical events within the window.
            if ($this->isDuplicate($locked, $type)) {
                return [
                    'event' => null,
                    'deduplicated' => true,
                    'counted' => false,
                    'attempt' => $locked,
                ];
            }

            $severity = $this->riskConfig->severity($type);
            $riskPoints = $enabled ? $this->riskConfig->riskPoints($type) : 0;

            if (! $enabled) {
                // Evidence preserved but does not inflate the score because the
                // corresponding protection is disabled for this attempt.
                $metadata = array_merge($metadata, ['ignored' => true, 'reason' => 'protection_disabled']);
                $severity = IntegritySeverity::Low;
            }

            $event = ExamIntegrityEvent::create([
                'attempt_id' => $locked->getKey(),
                'event_type' => $type->value,
                'occurred_at' => $occurredAt,
                'metadata' => $metadata ?: null,
                'severity' => $severity->value,
                'risk_points' => $riskPoints,
            ]);

            // Only enabled, risk-bearing events count toward the warning
            // threshold — an ignored/zero-risk event is not a violation.
            $counted = $enabled && $riskPoints > 0;

            if ($counted) {
                $locked->violation_warnings = (int) $locked->violation_warnings + 1;
            }

            // The warning count and the re-evaluated risk are written in one
            // UPDATE (none when nothing changed); the locked model already
            // holds the stored values, so it is not re-read.
            $this->refreshAttemptRisk($locked);

            return [
                'event' => $event,
                'deduplicated' => false,
                'counted' => $counted,
                'attempt' => $locked,
            ];
        });
    }

    /**
     * Effective warning threshold: frozen per-attempt value or config default.
     */
    private function warningThresholdFor(ExamAttempt $attempt): int
    {
        $attempt->loadMissing('integritySetting');

        return $this->riskConfig->resolveWarningThreshold(
            $attempt->integritySetting?->violation_warning_threshold
        );
    }

    /**
     * Same score and status as EvaluateAttemptRiskAction (the sum of every
     * recorded event's risk points, mapped through the configured thresholds),
     * computed by the database instead of loading every event.
     */
    private function refreshAttemptRisk(ExamAttempt $attempt): void
    {
        $riskScore = (int) $attempt->integrityEvents()->sum('risk_points');

        $this->updateStatus->apply(
            $attempt,
            $riskScore,
            $this->riskConfig->statusFor($riskScore)
        );
    }

    private function isDuplicate(ExamAttempt $attempt, IntegrityEventType $type): bool
    {
        $window = $this->riskConfig->deduplicationWindowSeconds();

        $query = ExamIntegrityEvent::query()
            ->where('attempt_id', $attempt->getKey())
            ->where('created_at', '>=', now()->subSeconds($window));

        // When a student leaves the tab, browsers fire visibilitychange (TAB_SWITCH)
        // and window.blur (WINDOW_BLUR) at the same time. Treat them as the same departure
        // event so a single departure is never recorded twice or double-counted.
        if (in_array($type, [IntegrityEventType::TabSwitch, IntegrityEventType::WindowBlur], true)) {
            $query->whereIn('event_type', [
                IntegrityEventType::TabSwitch->value,
                IntegrityEventType::WindowBlur->value,
            ]);
        } else {
            $query->where('event_type', $type->value);
        }

        return $query->exists();
    }

    private function settingEnabled(ExamAttempt $attempt, string $key): bool
    {
        $settings = $attempt->integritySetting;

        if ($settings) {
            return (bool) $settings->{$key};
        }

        $defaults = $this->riskConfig->defaults();

        return (bool) ($defaults[$key] ?? false);
    }

    private function normalizeOccurredAt(ExamAttempt $attempt, ?Carbon $occurredAt): Carbon
    {
        $now = now();
        $occurredAt ??= $now;

        // Reject unreasonable client timestamps (backdated or far in the future).
        // A small grace window absorbs client/server clock skew.
        $grace = 120;

        if (
            $occurredAt->lt($attempt->started_at->copy()->subSeconds($grace))
            || $occurredAt->gt($now->copy()->addSeconds($grace))
        ) {
            throw new InvalidAttemptStateException('The event timestamp is outside the acceptable range.');
        }

        return $occurredAt;
    }
}
