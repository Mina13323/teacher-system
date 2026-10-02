<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ForceDeleteStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forceDelete', $this->route('student')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $student */
        $student = $this->route('student');
        $expected = $student instanceof User
            ? 'FORCE DELETE STUDENT '.$student->getKey()
            : 'FORCE DELETE STUDENT';

        return [
            'confirmation' => ['required', 'string', Rule::in([$expected])],
            'delete_academic_history' => ['sometimes', 'boolean'],
        ];
    }
}
