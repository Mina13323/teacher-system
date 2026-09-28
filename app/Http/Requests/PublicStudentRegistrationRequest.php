<?php

namespace App\Http\Requests;

use App\Enums\AcademicYear;
use App\Rules\ValidPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class PublicStudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', new ValidPhoneNumber, 'unique:users,phone'],
            'academic_year' => ['required', new Enum(AcademicYear::class)],
        ];
    }
}
