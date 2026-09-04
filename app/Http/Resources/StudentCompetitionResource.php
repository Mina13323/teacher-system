<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing competition resource. Never exposes teacher-only metadata,
 * internal moderation data, or the answer key of the linked exam.
 *
 * @mixin \App\Models\Competition
 */
class StudentCompetitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'exam_title' => $this->whenLoaded('exam', fn () => $this->exam->title),
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status?->value,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'max_participants' => $this->max_participants,
            'scoring_type' => $this->scoring_type?->value,
            'ranking_type' => $this->ranking_type?->value,
            'participants_count' => $this->whenCounted('participants'),
            'is_joined' => $this->when(isset($this->is_joined), fn () => (bool) $this->is_joined),
        ];
    }
}
