<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAssistantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower($this->string('email')->toString())]);
        }
    }

    public function authorize(): bool
    {
        // Only the Teacher (or an admin) may create an assistant. The assistant
        // role itself never has this permission.
        return $this->user() !== null
            && ($this->user()->isAdmin() || $this->user()->hasPermissionTo('assistants.create'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
