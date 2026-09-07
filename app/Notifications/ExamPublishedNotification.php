<?php

namespace App\Notifications;

use App\Models\Exam;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies enrolled students that an exam has been published and is now
 * available to take.
 */
class ExamPublishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Exam $exam,
        private readonly string $message = '',
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
            'type' => 'exam_published',
            'title' => 'New exam available',
            'message' => $this->message ?: "The exam \"{$this->exam->title}\" has been published and is available to take.",
            'subject_id' => $this->exam->id,
            'subject_type' => 'exam',
        ];
    }
}
