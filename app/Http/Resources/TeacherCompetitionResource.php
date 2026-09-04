<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing competition resource. Exposes the full configuration and
 * management metadata a teacher needs.
 *
 * @mixin \App\Models\Competition
 */
class TeacherCompetitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status?->value,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'max_participants' => $this->max_participants,
            'scoring_type' => $this->scoring_type?->value,
            'ranking_type' => $this->ranking_type?->value,
            'exam' => $this->whenLoaded('exam', fn () => new ExamResource($this->exam)),
            'creator' => $this->whenLoaded('creator', fn () => new UserResource($this->creator)),
            'participants_count' => $this->whenCounted('participants'),
            'results_count' => $this->whenCounted('results'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
