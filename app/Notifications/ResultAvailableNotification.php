<?php

namespace App\Notifications;

use App\Models\ExamAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies a student that their exam result is available. Never includes the
 * score in the notification payload (that is fetched from the result endpoint);
 * a leaked notification must not expose the answer key or grading details.
 */
class ResultAvailableNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly ExamAttempt $attempt,
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
            'type' => 'result_available',
            'title' => 'Exam result available',
            'message' => 'Your exam result is now available to view.',
            'subject_id' => $this->attempt->id,
            'subject_type' => 'exam_attempt',
        ];
    }
}
