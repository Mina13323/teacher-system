<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A verified recovery has no current password to give (see ChangePasswordAction).
        if ($this->user()?->isRecoveringPassword()) {
            return [
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ];
        }

        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ];
    }
}
