<?php

namespace App\Http\Requests;

use App\Enums\AcademicSubject;
use App\Enums\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateStudentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && $this->filled('email')) {
            $this->merge(['email' => mb_strtolower($this->string('email')->toString())]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('student'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\User $student */
        $student = $this->route('student');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$student->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'academic_year' => ['sometimes', 'nullable', new Enum(AcademicYear::class)],
            'academic_subject' => ['sometimes', 'nullable', new Enum(AcademicSubject::class)],
            'can_access_lessons' => ['sometimes', 'nullable', 'boolean'],
            'can_take_exams' => ['sometimes', 'nullable', 'boolean'],
            'can_join_competitions' => ['sometimes', 'nullable', 'boolean'],
            'capability_preset' => ['sometimes', 'nullable', 'string', 'in:ALL,LESSONS_ONLY,EXAMS_ONLY,COMPETITIONS_ONLY,NONE,CUSTOM'],
        ];
    }
}
