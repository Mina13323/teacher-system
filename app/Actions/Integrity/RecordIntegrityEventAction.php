<?php

namespace App\Actions\Integrity;

use App\Enums\ExamAttemptStatus;
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
 *  - only accepts events for an in-progress, non-expired attempt,
 *  - assigns severity and risk points server-side (never from the client),
 *  - gates an event against the attempt's FROZEN integrity settings: if the
 *    corresponding protection is disabled the event is recorded as "ignored"
 *    (0 risk) rather than inflating the score,
 *  - deduplicates repeated identical events within a short window so a malicious
 *    client cannot flood the score,
 *  - re-evaluates the attempt's risk score and integrity status afterwards.
 *
 * @return array{event: ?ExamIntegrityEvent, deduplicated: bool}
 */
class RecordIntegrityEventAction
{
    public function __construct(
        private readonly IntegrityRiskConfig $riskConfig,
        private readonly EvaluateAttemptRiskAction $evaluateRisk,
        private readonly UpdateAttemptIntegrityStatusAction $updateStatus,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{event: ?ExamIntegrityEvent, deduplicated: bool}
     */
    public function execute(ExamAttempt $attempt, IntegrityEventType $type, ?Carbon $occurredAt, array $metadata = []): array
    {
        if (! $attempt->status->isInProgress() || $attempt->isExpired()) {
            throw new InvalidAttemptStateException('Integrity events can only be recorded for an active attempt.');
        }

        $occurredAt = $this->normalizeOccurredAt($attempt, $occurredAt);
        $attempt->loadMissing('integritySetting');

        $settingKey = $this->riskConfig->gateSetting($type);
        $enabled = $settingKey === null || $this->settingEnabled($attempt, $settingKey);

        return DB::transaction(function () use ($attempt, $type, $occurredAt, $metadata, $enabled) {
            // Lock the attempt so a concurrent submit cannot race a recording.
            $locked = ExamAttempt::query()->lockForUpdate()->find($attempt->getKey());

            if (! $locked->status->isInProgress() || $locked->isExpired()) {
                throw new InvalidAttemptStateException('Integrity events can only be recorded for an active attempt.');
            }

            // Deduplicate repeated identical events within the window.
            if ($this->isDuplicate($locked, $type)) {
                return ['event' => null, 'deduplicated' => true];
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

            $this->refreshAttemptRisk($locked);

            return ['event' => $event, 'deduplicated' => false];
        });
    }

    private function refreshAttemptRisk(ExamAttempt $attempt): void
    {
        $result = $this->evaluateRisk->execute($attempt);
        $this->updateStatus->execute(
            $attempt,
            $result['risk_score'],
            $result['integrity_status']
        );
    }

    private function isDuplicate(ExamAttempt $attempt, IntegrityEventType $type): bool
    {
        $window = $this->riskConfig->deduplicationWindowSeconds();

        return ExamIntegrityEvent::query()
            ->where('attempt_id', $attempt->getKey())
            ->where('event_type', $type->value)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->exists();
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
