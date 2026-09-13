<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class StudentRenewalDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly User $student,
        public readonly ?CarbonInterface $dueDate = null,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'student_renewal_due',
            'title' => 'Student Access Renewal Due',
            'message' => 'Monthly renewal for '.$this->student->name.' ('.($this->student->student_code ?? 'Student').') is overdue.',
            'student_id' => $this->student->getKey(),
            'student_name' => $this->student->name,
            'student_code' => $this->student->student_code,
            'academic_year' => $this->student->academic_year?->value,
            'academic_year_label' => $this->student->academic_year?->label(),
            'due_date' => $this->dueDate?->toISOString() ?? now()->toISOString(),
            'subject_id' => $this->student->getKey(),
            'subject_type' => 'student',
        ];
    }
}
