<?php

namespace App\Notifications;

use App\Models\Competition;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies enrolled students that a competition has been published and is now
 * open for registration/participation.
 */
class CompetitionPublishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Competition $competition,
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
            'type' => 'competition_published',
            'title' => 'New competition open',
            'message' => $this->message ?: "The competition \"{$this->competition->title}\" has been published and is open to join.",
            'subject_id' => $this->competition->id,
            'subject_type' => 'competition',
        ];
    }
}
