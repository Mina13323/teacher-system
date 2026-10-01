<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartExamAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('student');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rules_acknowledged' => ['required', 'accepted'],
            // Optional response optimization for clients that immediately fetch
            // the full attempt from the authorized attempt endpoint.
            'compact_response' => ['sometimes', 'boolean'],
        ];
    }
}
