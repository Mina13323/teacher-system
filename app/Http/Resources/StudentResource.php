<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profile representation for a student account as seen by a managing teacher or
 * the student themselves. Includes the profile completion state. Never includes
 * the password or any internal token.
 *
 * @mixin \App\Models\User
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'is_active' => $this->is_active,
            'profile_completed' => $this->isProfileComplete(),
            'profile_completed_at' => $this->profile_completed_at?->toISOString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
