<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing participant resource. Uses a safe public display name for the
 * student identity; the student's email / internal data is never exposed here.
 *
 * @mixin \App\Models\CompetitionParticipant
 */
class CompetitionParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'competition_id' => $this->competition_id,
            'student_id' => $this->student_id,
            'student_display_name' => $this->whenLoaded('student', fn () => $this->student->publicDisplayName()),
            'joined_at' => $this->joined_at?->toISOString(),
            'status' => $this->status?->value,
            'result' => $this->whenLoaded('result', fn () => $this->result ? new CompetitionResultResource($this->result) : null),
        ];
    }
}
