<?php

namespace App\Enums;

/**
 * Supported exam integrity event types. Extensible for later phases.
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

    /**
     * Event types a student's client may directly report. The aggregate
     * MULTIPLE_SUSPICIOUS_EVENTS condition is EXCLUDED: it is derived
     * server-side from actual recorded events and must never be submitted by a
     * client.
     *
     * @return list<self>
     */
    public static function clientReportable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case !== self::MultipleSuspiciousEvents
        ));
    }

    /**
     * Whether this event type may be reported directly by a student's client.
     */
    public function isClientReportable(): bool
    {
        return $this !== self::MultipleSuspiciousEvents;
    }
}
