<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Student submission: text answer and/or an uploaded file. The uploaded file
 * is stored on the PRIVATE disk and only streamed through authorized
 * endpoints — never publicly linked.
 */
class SubmitAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('student') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answer_text' => ['nullable', 'string', 'max:50000'],
            'file' => [
                'nullable',
                'file',
                'max:20480', // 20 MB
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,odt,ods,odp,zip,png,jpg,jpeg,webp',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'answer_text.required_without' => 'Provide a written answer or attach a file.',
        ];
    }
}
