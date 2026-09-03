<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing integrity event. Includes severity and risk points so the
 * teacher can trace a risk score back to the events that produced it.
 *
 * @mixin \App\Models\ExamIntegrityEvent
 */
class IntegrityEventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type?->value,
            'occurred_at' => $this->occurred_at?->toISOString(),
            'metadata' => $this->metadata,
            'severity' => $this->severity?->value,
            'risk_points' => $this->risk_points,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
