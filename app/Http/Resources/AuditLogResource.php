<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'role' => $this->actor_role,
            ]),
            'actor_id' => $this->actor_id,
            'actor_role' => $this->actor_role,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
