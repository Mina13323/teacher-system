<?php

namespace App\Http\Resources;

use App\Enums\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $academicYearEnum = $this->academic_year instanceof AcademicYear
            ? $this->academic_year
            : ($this->academic_year ? AcademicYear::tryFrom($this->academic_year) : null);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'student_code' => $this->student_code,
            'avatar' => $this->avatar,
            'academic_year' => $academicYearEnum?->value,
            'academic_year_label' => $academicYearEnum?->label(),
            'is_active' => $this->is_active,
            'can_access_lessons' => $this->canAccessLessons(),
            'can_take_exams' => $this->canTakeExams(),
            'can_join_competitions' => $this->canJoinCompetitions(),
            'has_active_access' => $this->hasActiveAccess(),
            'access_status' => $this->accessStatus(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
