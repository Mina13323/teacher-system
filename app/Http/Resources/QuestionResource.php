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
            'image_url' => $this->imageUrl(),
            'type' => $this->type?->value,
            'points' => $this->points,
            'position' => $this->position,
            'reference_answer' => $this->when($request->user()?->isStaff() || $request->user()?->isAdmin(), $this->reference_answer),
            'options' => OptionResource::collection(
                $this->whenLoaded('options', $this->options)
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
