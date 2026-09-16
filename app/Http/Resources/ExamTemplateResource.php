<?php

namespace App\Http\Resources;

use App\Models\ExamTemplateSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing resource describing a reusable exam structure.
 *
 * NOTE: `total_questions` and `total_points` are derived from the `sections`
 * relation, so every endpoint returning a collection of these MUST eager-load
 * it — otherwise each row issues its own query.
 *
 * @mixin \App\Models\ExamTemplate
 */
class ExamTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            // A seeded template carries a stable key so the UI can render its
            // label through the translation catalog; a teacher's own template
            // has none and is displayed from `name` verbatim.
            'preset_key' => $this->preset_key,
            'created_by' => $this->created_by,
            'editable' => $request->user() !== null && $this->isEditableBy($request->user()),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections
                ->map(fn (ExamTemplateSection $section) => [
                    'question_type' => $section->question_type?->value,
                    'quantity' => $section->quantity,
                    'points' => $section->points,
                    'position' => $section->position,
                ])
                ->values()),
            // Pre-computed so the template picker can show "50 questions · 70
            // marks" without the client re-deriving it from the section list.
            'total_questions' => $this->totalQuestions(),
            'total_points' => $this->totalPoints(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
