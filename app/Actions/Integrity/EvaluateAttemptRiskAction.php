<?php

namespace App\Actions\Integrity;

use App\Enums\IntegrityStatus;
use App\Models\ExamAttempt;
use App\Services\Integrity\IntegrityRiskConfig;

/**
 * Deterministic risk evaluation for an attempt.
 *
 * The total risk score is the sum of the risk points of every recorded integrity
 * event (ignored/deduplicated events contribute 0), and the automatic integrity
 * status is derived from that total using the configured thresholds. The result
 * is fully traceable to the events that produced it.
 *
 * The `multiple_suspicious_events` flag is a server-derived CONDITION, not an
 * event. It is computed from the distinct risk-bearing event types that were
 * actually recorded and does NOT add any synthetic risk, so there is never
 * double-counting.
 *
 * @return array{risk_score: int, integrity_status: IntegrityStatus, multiple_suspicious_events: bool}
 */
class EvaluateAttemptRiskAction
{
    public function __construct(
        private readonly IntegrityRiskConfig $riskConfig,
    ) {
    }

    public function execute(ExamAttempt $attempt): array
    {
        $events = $attempt->integrityEvents()->get();
        $riskScore = (int) $events->sum('risk_points');

        return [
            'risk_score' => $riskScore,
            'integrity_status' => $this->riskConfig->statusFor($riskScore),
            'multiple_suspicious_events' => $this->riskConfig->isMultipleSuspicious($events),
        ];
    }
}
