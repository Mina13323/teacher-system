<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssistantRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower($this->string('email')->toString())]);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        /** @var User $assistant */
        $assistant = $this->route('assistant');

        if (! $user || ! $assistant) {
            return false;
        }

        return $user->isAdmin()
            || ((int) $assistant->created_by === (int) $user->getKey()
                && $user->hasPermissionTo('assistants.manage'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $assistant */
        $assistant = $this->route('assistant');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,'.$assistant->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
