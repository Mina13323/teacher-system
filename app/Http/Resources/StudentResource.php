<?php

namespace App\Http\Resources;

use App\Enums\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Profile representation for a student account as seen by a managing teacher or
 * the student themselves. Includes capabilities, academic year, and access lifecycle.
 * Never includes the password or any internal token.
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
        $academicYearEnum = $this->academic_year instanceof AcademicYear
            ? $this->academic_year
            : ($this->academic_year ? AcademicYear::tryFrom($this->academic_year) : null);

        $latestPeriod = $this->latestAccessPeriod;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'student_code' => $this->student_code,
            'avatar' => $this->avatar,
            'phone' => $this->phone,
            'bio' => $this->bio,
            'academic_year' => $academicYearEnum?->value,
            'academic_year_label' => $academicYearEnum?->label(),
            'is_active' => $this->is_active,
            'can_access_lessons' => $this->canAccessLessons(),
            'can_take_exams' => $this->canTakeExams(),
            'can_join_competitions' => $this->canJoinCompetitions(),
            'capability_preset' => $this->capabilityPreset(),
            'has_active_access' => $this->hasActiveAccess(),
            'access_status' => $this->accessStatus(),
            'access_expires_at' => $latestPeriod?->expires_at?->toISOString(),
            'latest_access_period' => $latestPeriod ? [
                'id' => $latestPeriod->id,
                'status' => $latestPeriod->status?->value,
                'status_label' => $latestPeriod->status?->label(),
                'starts_at' => $latestPeriod->starts_at?->toISOString(),
                'expires_at' => $latestPeriod->expires_at?->toISOString(),
                'amount' => $latestPeriod->amount,
                'notes' => $latestPeriod->notes,
                'approved_at' => $latestPeriod->approved_at?->toISOString(),
            ] : null,
            'profile_completed' => $this->isProfileComplete(),
            'profile_completed_at' => $this->profile_completed_at?->toISOString(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
