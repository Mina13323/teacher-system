<?php

namespace App\Enums;

/**
 * Content-protection events for protected video playback.
 *
 * Split into two classes:
 *  - Server-authoritative events (granted / denied / expired) are recorded by
 *    the backend itself and are high-trust.
 *  - Client-reportable deterrence detections (fullscreen exit, tab switch,
 *    window blur, devtools detection) are reported by the student's browser as
 *    low-trust signals and are only for auditing / awareness, never proof.
 *
 * No clipboard contents, keystroke streams, or personal media data are ever
 * accepted.
 */
enum VideoPlaybackEventType: string
{
    case PlaybackGranted = 'PLAYBACK_GRANTED';
    case PlaybackDenied = 'PLAYBACK_DENIED';
    case SessionExpired = 'SESSION_EXPIRED';
    case FullscreenExit = 'FULLSCREEN_EXIT';
    case TabSwitch = 'TAB_SWITCH';
    case WindowBlur = 'WINDOW_BLUR';
    case DevtoolsDetection = 'DEVTOOLS_DETECTION';

    /**
     * Event types a student's client may report (deterrence detections only).
     * Server-authoritative states are excluded; they are recorded by the backend.
     *
     * @return list<self>
     */
    public static function clientReportable(): array
    {
        return [
            self::FullscreenExit,
            self::TabSwitch,
            self::WindowBlur,
            self::DevtoolsDetection,
        ];
    }

    public function isClientReportable(): bool
    {
        return in_array($this, self::clientReportable(), true);
    }
}
