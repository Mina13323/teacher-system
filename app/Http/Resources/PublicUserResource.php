<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal, privacy-safe representation of a user for public/unauthenticated
 * contexts (e.g. the public course catalog). Never exposes an email address,
 * internal moderation flags, or account metadata.
 *
 * @mixin \App\Models\User
 */
class PublicUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->publicDisplayName(),
        ];
    }
}
