<?php

namespace App\Http\Requests;

use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExamIntegritySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Exam $exam */
        $exam = $this->route('exam');

        return $this->user()->can('update', $exam);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fullscreen_required' => ['sometimes', 'boolean'],
            'prevent_copy' => ['sometimes', 'boolean'],
            'prevent_paste' => ['sometimes', 'boolean'],
            'prevent_context_menu' => ['sometimes', 'boolean'],
            'detect_tab_switch' => ['sometimes', 'boolean'],
            'detect_window_blur' => ['sometimes', 'boolean'],
            'detect_keyboard_shortcuts' => ['sometimes', 'boolean'],
        ];
    }
}
