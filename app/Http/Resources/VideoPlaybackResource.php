<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The protected playback payload for an authorized student. This is the ONLY
 * student-facing response that carries a playable media reference, and it is
 * returned only after full server-side authorization, inside a short-lived
 * session (see Student\VideoController::playback and VideoPolicy::play).
 *
 * It contains just enough to render the protected player:
 *   - minimal learning metadata,
 *   - the provider name (so the frontend picks the right player),
 *   - the provider's media reference necessary to play,
 *   - a short-lived session token + expiry,
 *   - the content-protection/deterrence config,
 *   - the dynamic watermark content.
 *
 * It NEVER returns a channel URL, channel id, playlist id, embed page URL,
 * download URL, storage path, or any internal provider credential.
 *
 * @mixin \App\Models\VideoPlaybackSession
 */
class VideoPlaybackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;
        $session->loadMissing(['video', 'student']);

        $video = $session->video;
        $student = $session->student;

        return [
            'video' => [
                'id' => $video->id,
                'lesson_id' => $video->lesson_id,
                'title' => $video->title,
                'duration' => $video->duration,
            ],
            'playback' => [
                // The provider name (e.g. "youtube" / "storage") lets the client
                // choose the right player without exposing any provider-specific
                // endpoint, channel or account metadata.
                'provider' => $video->provider?->value,
                // The single media reference required to play. For a YouTube
                // embed this is the video id; the browser must receive it to
                // play. It is not a channel / playlist / account reference.
                'media_ref' => $video->providerReference(),
                'token' => $session->token,
                'expires_at' => $session->expires_at?->toISOString(),
            ],
            'protection' => config('video.protection', []),
            'watermark' => $this->watermarkPayload($student, $session),
        ];
    }

    /**
     * Build a non-secret, non-removable-in-UI watermark for leak attribution.
     *
     * @return array<string, mixed>
     */
    private function watermarkPayload($student, $session): array
    {
        $watermarkConfig = config('video.watermark', []);

        if (! ($watermarkConfig['enabled'] ?? false)) {
            return ['enabled' => false];
        }

        $displayName = $student?->publicDisplayName() ?? 'Participant';
        $sessionId = $session->getKey();
        // An opaque, short-lived session marker; the full token is never shown.
        $code = strtoupper(substr(hash('sha256', (string) $session->token), 0, 6));

        return [
            'enabled' => true,
            'text' => trim($displayName).' · '.$code,
            'session_id' => (int) $sessionId,
            'repeating' => (bool) ($watermarkConfig['repeating'] ?? true),
            'rotate_interval_seconds' => (int) ($watermarkConfig['rotate_interval_seconds'] ?? 8),
            'opacity' => (float) ($watermarkConfig['opacity'] ?? 0.18),
        ];
    }
}
