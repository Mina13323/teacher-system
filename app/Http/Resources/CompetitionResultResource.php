<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing competition result resource. Includes the source attempt's
 * integrity status so a teacher can review a flagged attempt before deciding on
 * qualification/disqualification.
 *
 * @mixin \App\Models\CompetitionResult
 */
class CompetitionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'participant_id' => $this->participant_id,
            'attempt_id' => $this->attempt_id,
            'score' => $this->score,
            'percentage' => $this->percentage,
            'completion_time' => $this->completion_time,
            'rank' => $this->rank,
            'qualified' => $this->qualified,
            'integrity_status' => $this->whenLoaded('attempt', fn () => $this->attempt->integrity_status?->value),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
