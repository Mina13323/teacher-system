<?php

namespace App\Http\Requests;

use App\Models\ExamAttempt;
use Illuminate\Foundation\Http\FormRequest;

class SubmitExamAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamAttempt $attempt */
        $attempt = $this->route('attempt');

        return $this->user()->can('update', $attempt);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer'],
            'option_id' => ['nullable', 'integer'],
            'answer_text' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
