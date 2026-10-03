<?php

namespace App\Http\Requests;

use App\Models\Question;
use Illuminate\Foundation\Http\FormRequest;

class RegradeQuestionAttemptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Question $question */
        $question = $this->route('question');

        $user = $this->user();

        return $question !== null && $user !== null && $user->can('update', $question);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'correct_option_ids' => ['sometimes', 'array'],
            'correct_option_ids.*' => ['integer'],
            'correct_option_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
