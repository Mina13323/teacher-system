<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;

class CreateQuestionRequest extends FormRequest
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
            'question_text' => ['required', 'string'],
            'type' => ['nullable', 'string', 'in:'.QuestionType::SingleChoice->value],
            'points' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
