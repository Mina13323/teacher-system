<?php

namespace App\Enums;

/**
 * Supported exam integrity event types. Extensible for later phases.
 *
 * Two classes of events exist:
 *   CLIENT-REPORTABLE — browser-observable signals a student's client may
 *     report (explicit allowlist below). The server treats them as SUSPICIOUS
 *     ACTIVITY INDICATORS, never as proof of cheating.
 *   SERVER-ONLY — derived by the backend and never accepted from a client:
 *     MULTIPLE_SUSPICIOUS_EVENTS (derived condition) and THRESHOLD_TERMINATION
 *     (recorded when the warning threshold policy ends an attempt).
 *
 * Honesty rule: an event may only be recorded when the corresponding thing was
 * actually observed. A heartbeat timeout or network failure is NEVER converted
 * into WINDOW_BLUR or any other event type.
 */
enum IntegrityEventType: string
{
    case TabSwitch = 'TAB_SWITCH';
    case WindowBlur = 'WINDOW_BLUR';
    case WindowFocus = 'WINDOW_FOCUS';
    case FullscreenEnter = 'FULLSCREEN_ENTER';
    case FullscreenExit = 'FULLSCREEN_EXIT';
    case CopyAttempt = 'COPY_ATTEMPT';
    case PasteAttempt = 'PASTE_ATTEMPT';
    case CutAttempt = 'CUT_ATTEMPT';
    case ContextMenuAttempt = 'CONTEXT_MENU_ATTEMPT';
    case KeyboardShortcut = 'KEYBOARD_SHORTCUT';
    case MultipleSuspiciousEvents = 'MULTIPLE_SUSPICIOUS_EVENTS';
    case ThresholdTermination = 'THRESHOLD_TERMINATION';

    /**
     * Event types a student's client may directly report. This is an EXPLICIT
     * allowlist of browser-observable signals — derived and server-only types
     * can never be added here by accident.
     *
     * @return list<self>
     */
    public static function clientReportable(): array
    {
        return [
            self::TabSwitch,
            self::WindowBlur,
            self::WindowFocus,
            self::FullscreenEnter,
            self::FullscreenExit,
            self::CopyAttempt,
            self::PasteAttempt,
            self::CutAttempt,
            self::ContextMenuAttempt,
            self::KeyboardShortcut,
        ];
    }

    /**
     * Whether this event type may be reported directly by a student's client.
     */
    public function isClientReportable(): bool
    {
        return in_array($this, self::clientReportable(), true);
    }

    /**
     * Whether this is a server-only type.
     */
    public function isServerOnly(): bool
    {
        return ! $this->isClientReportable();
    }
}
