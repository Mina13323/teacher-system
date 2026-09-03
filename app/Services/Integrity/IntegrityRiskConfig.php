<?php

namespace App\Services\Integrity;

use App\Enums\IntegrityEventType;
use App\Enums\IntegritySeverity;
use App\Enums\IntegrityStatus;
use Illuminate\Support\Collection;

/**
 * Reads the central integrity configuration and exposes the deterministic risk
 * model used to score events and derive an attempt's integrity status. This is
 * the single source of truth for risk points, severity, thresholds and the
 * deduplication window — no magic numbers are scattered in the codebase.
 */
class IntegrityRiskConfig
{
    /**
     * Baseline risk points for an event type.
     */
    public function riskPoints(IntegrityEventType $type): int
    {
        return (int) (config('integrity.risk_points.'.$type->value) ?? 0);
    }

    /**
     * Baseline severity for an event type.
     */
    public function severity(IntegrityEventType $type): IntegritySeverity
    {
        $value = config('integrity.severity.'.$type->value, IntegritySeverity::Low->value);

        return IntegritySeverity::from($value);
    }

    /**
     * The frozen setting key that gates an event type, or null if the event is
     * always allowed.
     */
    public function gateSetting(IntegrityEventType $type): ?string
    {
        return config('integrity.event_settings.'.$type->value);
    }

    /**
     * The risk threshold at which an attempt becomes monitoring.
     */
    public function monitoringThreshold(): int
    {
        return (int) config('integrity.thresholds.monitoring', 3);
    }

    /**
     * The risk threshold at which an attempt becomes flagged.
     */
    public function flaggedThreshold(): int
    {
        return (int) config('integrity.thresholds.flagged', 6);
    }

    /**
     * Determines the automatic integrity status from a total risk score.
     */
    public function statusFor(int $riskScore): IntegrityStatus
    {
        if ($riskScore >= $this->flaggedThreshold()) {
            return IntegrityStatus::Flagged;
        }

        if ($riskScore >= $this->monitoringThreshold()) {
            return IntegrityStatus::Monitoring;
        }

        return IntegrityStatus::Normal;
    }

    /**
     * Number of seconds within which repeated identical events are deduplicated.
     */
    public function deduplicationWindowSeconds(): int
    {
        return max(1, (int) config('integrity.dedup_window_seconds', 5));
    }

    /**
     * The minimum number of distinct risk-bearing event types required for an
     * attempt to be treated as "multiple suspicious events".
     */
    public function multipleSuspiciousMinEventTypes(): int
    {
        return max(1, (int) config('integrity.multiple_suspicious_min_event_types', 2));
    }

    /**
     * Determines whether an attempt exhibits a server-derived "multiple
     * suspicious events" condition from its recorded, risk-bearing events. This
     * is informational only — it does NOT add synthetic risk. The attempt's risk
     * score already reflects each individual event, so this is never a duplicate
     * or bonus score.
     *
     * @param  Collection<int, \App\Models\ExamIntegrityEvent>  $events
     */
    public function isMultipleSuspicious(Collection $events): bool
    {
        $distinctRiskBearingTypes = $events
            ->where('risk_points', '>', 0)
            ->pluck('event_type')
            ->reject(fn ($type) => $type === null)
            ->unique()
            ->count();

        return $distinctRiskBearingTypes >= $this->multipleSuspiciousMinEventTypes();
    }

    /**
     * Default integrity settings for a new exam (used when none are configured).
     *
     * @return array<string, bool>
     */
    public function defaults(): array
    {
        return config('integrity.defaults');
    }
}
