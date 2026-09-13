<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('email')) {
            if ($this->has('login')) {
                $this->merge(['email' => $this->input('login')]);
            } elseif ($this->has('student_code')) {
                $this->merge(['email' => $this->input('student_code')]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (str_contains($value, '@')) {
                        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            $fail('The email field must be a valid email address.');
                        }
                    } elseif (! preg_match('/^ELM-\d+$/i', $value)) {
                        $fail('The email field must be a valid email address or student code.');
                    }
                },
            ],
            'password' => ['required', 'string'],
        ];
    }
}
