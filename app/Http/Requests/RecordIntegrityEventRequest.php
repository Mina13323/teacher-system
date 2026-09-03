<?php

namespace App\Http\Requests;

use App\Enums\IntegrityEventType;
use App\Models\ExamAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordIntegrityEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamAttempt $attempt */
        $attempt = $this->route('attempt');

        return $this->user()->can('recordEvent', $attempt);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', Rule::enum(IntegrityEventType::class)],
            'occurred_at' => ['nullable', 'date'],
            // Limited, privacy-conscious metadata only. Never accept clipboard
            // contents, keystroke streams, or arbitrary client payloads.
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
