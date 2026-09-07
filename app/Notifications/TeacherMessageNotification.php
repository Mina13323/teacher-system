<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A free-form teacher-to-student message. The message is stored as part of the
 * notification record so it is visible to the recipient in-app.
 */
class TeacherMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly User $teacher,
        public readonly string $message,
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
            'type' => 'teacher_message',
            'title' => 'Message from your teacher',
            'message' => $this->message,
            'subject_id' => $this->teacher->id,
            'subject_type' => 'teacher',
        ];
    }
}
