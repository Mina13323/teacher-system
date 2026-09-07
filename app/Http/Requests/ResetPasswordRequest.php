<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes a manager (teacher/admin) to reset a managed account's password.
 *
 *  - Admin may reset any account.
 *  - A teacher may reset a student they manage (the `{student}` route in the
 *    teacher student routes). The teacher route always binds `{student}`; the
 *    admin teacher route binds `{teacher}`, which is handled by the admin check.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user->isAdmin()) {
            return true;
        }

        /** @var User|null $student */
        $student = $this->route('student');

        return $student !== null && $user->can('manage', $student);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
