<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A scheduled reminder (exam opening/closing, assignment deadline, competition
 * ending) delivered in-app (database channel).
 *
 * `dedupe_key` is persisted inside the notification data so the scheduler can
 * prove — per recipient — that a given reminder was already delivered and must
 * not be sent twice.
 */
class ScheduledReminderNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly string $dedupeKey,
        public readonly ?string $url = null,
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
            'type' => 'scheduled_reminder',
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'subject_id' => $this->subjectId,
            'subject_type' => $this->subjectType,
            'dedupe_key' => $this->dedupeKey,
            'url' => $this->url,
        ];
    }
}
