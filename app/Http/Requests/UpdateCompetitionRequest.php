<?php

namespace App\Http\Requests;

use App\Models\Competition;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompetitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Competition $competition */
        $competition = $this->route('competition');

        return $this->user()->can('update', $competition);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['sometimes', 'nullable', 'date', 'required_with:ends_at'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'required_with:starts_at', 'after:starts_at'],
            'max_participants' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // scoring_type / ranking_type / exam_id are intentionally absent
            // from the update payload: they are immutable once the competition
            // moves beyond DRAFT (see UpdateCompetitionAction).
        ];
    }
}
