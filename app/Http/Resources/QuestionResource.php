<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing resource. Note: options carry `is_correct` so a teacher can
 * manage the answer key; this resource is NEVER used for student exam-taking.
 *
 * @mixin \App\Models\Question
 */
class QuestionResource extends JsonResource
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
            'exam_id' => $this->exam_id,
            'question_text' => $this->question_text,
            'type' => $this->type?->value,
            'points' => $this->points,
            'position' => $this->position,
            'options' => OptionResource::collection(
                $this->whenLoaded('options', $this->options)
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
