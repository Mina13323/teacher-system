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
}
