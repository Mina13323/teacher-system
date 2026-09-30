<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() ?? false;

        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'name' => $this->student->name,
                'student_code' => $this->student->student_code,
            ]),
            'answer_text' => $this->answer_text,
            'file' => $this->file_path ? [
                'name' => $this->file_name,
                'mime' => $this->file_mime,
                // Authorized download endpoint — never a raw storage path.
                'download_url' => '/api/v1/assignment-submissions/'.$this->id.'/file',
            ] : null,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'is_late' => $this->isLate(),
            'score' => $this->score,
            'feedback' => $this->feedback,
            'graded_at' => $this->graded_at?->toISOString(),
            // Internal grading metadata stays staff-only.
            'graded_by' => $this->when($isStaff, fn () => $this->graded_by),
        ];
    }
}
