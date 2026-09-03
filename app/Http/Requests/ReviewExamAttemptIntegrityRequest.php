<?php

namespace App\Http\Requests;

use App\Enums\IntegrityReviewDecision;
use App\Models\ExamAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewExamAttemptIntegrityRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamAttempt $attempt */
        $attempt = $this->route('attempt');

        return $this->user()->can('review', $attempt);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::enum(IntegrityReviewDecision::class)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
