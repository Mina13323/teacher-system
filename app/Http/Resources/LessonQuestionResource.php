<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PHASE 4 §31 — Q&A entry. Deleted posts keep their slot (thread integrity)
 * but expose no body ("[removed]").
 */
class LessonQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'parent_id' => $this->parent_id,
            'body' => $this->is_deleted ? '[removed]' : $this->body,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'role' => $this->user?->roles?->first()?->name,
            ],
            'is_deleted' => (bool) $this->is_deleted,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'replies' => $this->whenLoaded(
                'replies',
                fn () => self::collection($this->replies->sortBy('created_at')->values())
            ),
        ];
    }
}
