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
 * @return array{risk_score: int, integrity_status: IntegrityStatus}
 */
class EvaluateAttemptRiskAction
{
    public function __construct(
        private readonly IntegrityRiskConfig $riskConfig,
    ) {
    }

    public function execute(ExamAttempt $attempt): array
    {
        $riskScore = (int) $attempt->integrityEvents()->sum('risk_points');

        return [
            'risk_score' => $riskScore,
            'integrity_status' => $this->riskConfig->statusFor($riskScore),
        ];
    }
}
