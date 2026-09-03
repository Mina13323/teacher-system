<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionRequest extends FormRequest
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
            'question_text' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string', 'in:'.QuestionType::SingleChoice->value],
            'points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
