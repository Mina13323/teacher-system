<?php

namespace App\Http\Requests;

use App\Enums\AcademicSubject;
use App\Enums\AcademicYear;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class CreateStudentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && $this->filled('email')) {
            $this->merge(['email' => mb_strtolower($this->string('email')->toString())]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'student_code' => ['nullable', 'string', 'max:64', 'unique:users,student_code'],
            'academic_year' => ['nullable', new Enum(AcademicYear::class)],
            'academic_subject' => ['nullable', new Enum(AcademicSubject::class)],
            'can_access_lessons' => ['nullable', 'boolean'],
            'can_take_exams' => ['nullable', 'boolean'],
            'can_join_competitions' => ['nullable', 'boolean'],
            'capability_preset' => ['nullable', 'string', 'in:ALL,LESSONS_ONLY,EXAMS_ONLY,COMPETITIONS_ONLY,NONE,CUSTOM'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['integer', 'exists:courses,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
