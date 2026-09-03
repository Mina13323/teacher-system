<?php

namespace App\Http\Requests;

use App\Models\Option;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Option $option */
        $option = $this->route('option');

        return $this->user()->can('update', $option);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'option_text' => ['sometimes', 'string'],
            'is_correct' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
