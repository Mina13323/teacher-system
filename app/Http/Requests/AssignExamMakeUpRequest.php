<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignExamMakeUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $exam = $this->route('exam');

        return $exam !== null && $this->user()?->can('assignMakeUp', $exam);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['required', 'integer', 'distinct'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
