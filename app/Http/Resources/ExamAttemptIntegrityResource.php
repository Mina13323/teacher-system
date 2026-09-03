<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing attempt integrity summary. Combines the attempt's integrity
 * status and risk score with its recorded events and review audit trail, so a
 * teacher can trace the score back to the evidence. Never returned to students.
 *
 * @mixin \App\Models\ExamAttempt
 */
class ExamAttemptIntegrityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attempt_id' => $this->id,
            'exam_id' => $this->exam_id,
            'exam_title' => $this->whenLoaded('exam', fn () => $this->exam->title),
            'student' => $this->whenLoaded('student', fn () => new UserResource($this->student)),
            'attempt_number' => $this->attempt_number,
            'status' => $this->status?->value,
            'integrity_status' => $this->integrity_status?->value,
            'risk_score' => $this->risk_score,
            'event_count' => $this->when(
                $this->relationLoaded('integrityEvents'),
                fn () => $this->integrityEvents->count()
            ),
            'events' => IntegrityEventResource::collection(
                $this->whenLoaded('integrityEvents', $this->integrityEvents)
            ),
            'frozen_settings' => $this->whenLoaded('integritySetting', fn () => [
                'fullscreen_required' => $this->integritySetting?->fullscreen_required,
                'prevent_copy' => $this->integritySetting?->prevent_copy,
                'prevent_paste' => $this->integritySetting?->prevent_paste,
                'prevent_context_menu' => $this->integritySetting?->prevent_context_menu,
                'detect_tab_switch' => $this->integritySetting?->detect_tab_switch,
                'detect_window_blur' => $this->integritySetting?->detect_window_blur,
                'detect_keyboard_shortcuts' => $this->integritySetting?->detect_keyboard_shortcuts,
            ]),
            'reviews' => IntegrityReviewResource::collection(
                $this->whenLoaded('integrityReviews', $this->integrityReviews)
            ),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
        ];
    }
}
