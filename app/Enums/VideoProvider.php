<?php

namespace App\Enums;

/**
 * The media provider a video is served from.
 *
 * Kept deliberately small and provider-agnostic so the LMS can be pointed at a
 * different provider (e.g. a streaming service with signed/DRM-protected
 * playback) without changing the student-facing API contract.
 */
enum VideoProvider: string
{
    case Storage = 'storage';
    case Youtube = 'youtube';

    public function label(): string
    {
        return match ($this) {
            self::Storage => 'Storage',
            self::Youtube => 'YouTube',
        };
    }
}
