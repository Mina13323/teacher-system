<?php

namespace App\Http\Requests;

use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;

class CreateOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Question $question */
        $question = $this->route('question');

        return $this->user()->can('update', $question);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'option_text' => ['required', 'string'],
            'is_correct' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
