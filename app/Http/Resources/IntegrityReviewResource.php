<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing integrity review record (part of the immutable audit trail).
 *
 * @mixin \App\Models\ExamIntegrityReview
 */
class IntegrityReviewResource extends JsonResource
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
            'attempt_id' => $this->attempt_id,
            'decision' => $this->decision?->value,
            'note' => $this->note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'reviewer' => $this->whenLoaded('reviewer', fn () => new UserResource($this->reviewer)),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
