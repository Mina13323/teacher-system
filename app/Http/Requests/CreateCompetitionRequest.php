<?php

namespace App\Http\Requests;

use App\Models\Competition;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;

class CreateCompetitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('create', Competition::class)) {
            return false;
        }

        $exam = Exam::find($this->input('exam_id'));

        if (! $exam) {
            return false;
        }

        // A teacher may only build a competition on top of an exam they manage.
        return $this->user()->can('view', $exam);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'exam_id' => ['required', 'integer', 'exists:exams,id'],
            'starts_at' => ['nullable', 'date', 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date', 'required_with:starts_at', 'after:starts_at'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'scoring_type' => ['nullable', 'string', 'in:highest_score,best_attempt'],
            'ranking_type' => ['nullable', 'string', 'in:score_desc'],
        ];
    }
}
