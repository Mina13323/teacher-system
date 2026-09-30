<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Push\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * PHASE 2 §25/§27 — Deliver one Web Push payload to a user's subscriptions.
 *
 * Unique per (user, dedupe key) so the reminder sweep can never fan out the
 * same push twice even if a job is retried. The database notification is the
 * durable record and is written separately; this job is a best-effort extra
 * channel (transport failures are swallowed — see WebPushSender).
 */
class SendWebPushJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly int $userId,
        private readonly string $title,
        private readonly string $body,
        private readonly ?string $url,
        private readonly array $data,
        private readonly string $uniqueKey,
    ) {
    }

    public function uniqueId(): string
    {
        return 'webpush:'.$this->uniqueKey;
    }

    public function handle(WebPushSender $sender): void
    {
        if (! $sender->isEnabled()) {
            return; // graceful fallback: in-app notification already recorded
        }

        $user = User::query()->find($this->userId);
        if ($user === null || ! $user->isActive()) {
            return;
        }

        $sender->sendToUser($user, $this->title, $this->body, $this->url, $this->data);
    }
}
