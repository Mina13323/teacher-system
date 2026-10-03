<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnonymizeStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('anonymize', $this->route('student')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('confirmation') && is_string($this->confirmation)) {
            $this->merge([
                'confirmation' => strtoupper(trim($this->confirmation)),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $student */
        $student = $this->route('student');
        $expected = $student instanceof User
            ? 'ANONYMIZE STUDENT '.$student->getKey()
            : 'ANONYMIZE STUDENT';

        return [
            'confirmation' => ['required', 'string', Rule::in([$expected])],
        ];
    }
}
