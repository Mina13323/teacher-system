<?php

namespace App\Http\Resources;

use App\Enums\EnrollmentStatus;
use App\Services\EnrollmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Catalog-safe course detail. Preview metadata is available to signed-in users,
 * but lesson bodies and internal media references are reserved for the
 * enrollment-gated lesson/video endpoints and authorized staff routes.
 *
 * @mixin \App\Models\Course
 */
class CoursePreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $enrollmentStatus = $user?->isStudent()
            ? app(EnrollmentService::class)->statusFor($user, (int) $this->id)
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail' => $this->thumbnail,
            'status' => $this->status?->value,
            'creator' => $this->whenLoaded('creator', fn () => new PublicUserResource($this->creator)),
            'units_count' => $this->whenCounted('units'),
            'lessons_count' => $this->whenCounted('lessons'),
            'enrollments_count' => $this->whenCounted('enrollments'),
            'is_enrolled' => $enrollmentStatus === EnrollmentStatus::Active->value,
            'enrollment_status' => $enrollmentStatus,
            'units' => UnitPreviewResource::collection($this->whenLoaded('units')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
