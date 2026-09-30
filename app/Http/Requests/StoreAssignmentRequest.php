<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'points' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'due_at' => ['nullable', 'date'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
