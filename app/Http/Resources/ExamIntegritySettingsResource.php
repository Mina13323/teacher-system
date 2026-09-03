<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing exam integrity configuration. Never returned to students.
 *
 * @mixin \App\Models\ExamIntegritySetting
 */
class ExamIntegritySettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'exam_id' => $this->exam_id,
            'fullscreen_required' => $this->fullscreen_required,
            'prevent_copy' => $this->prevent_copy,
            'prevent_paste' => $this->prevent_paste,
            'prevent_context_menu' => $this->prevent_context_menu,
            'detect_tab_switch' => $this->detect_tab_switch,
            'detect_window_blur' => $this->detect_window_blur,
            'detect_keyboard_shortcuts' => $this->detect_keyboard_shortcuts,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
