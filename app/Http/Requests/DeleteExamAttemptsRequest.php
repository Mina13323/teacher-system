<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteExamAttemptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam !== null && $this->user()?->can('deleteAttempts', $exam);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attempt_ids' => ['required', 'array', 'min:1', 'max:100'],
            'attempt_ids.*' => ['required', 'integer', 'distinct'],
            'confirmed' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
