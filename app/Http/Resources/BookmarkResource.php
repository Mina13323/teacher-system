<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookmarkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'video_id' => $this->video_id,
            'position_seconds' => $this->position_seconds,
            'label' => $this->label,
            'lesson' => $this->whenLoaded('lesson'),
            'video' => $this->whenLoaded('video'),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
