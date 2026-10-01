<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff-facing make-up assignment record. */
class ExamMakeUpAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'student_code' => $this->student->student_code,
                'email' => $this->student->email,
            ]),
            'assigned_by' => $this->assigned_by,
            'status' => $this->status?->value,
            'attempt_id' => $this->attempt_id,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'used_at' => $this->used_at?->toISOString(),
            'reason' => $this->reason,
        ];
    }
}
