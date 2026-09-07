<?php

/*
|--------------------------------------------------------------------------
| Video content protection
|--------------------------------------------------------------------------
|
| Application-level video playback protection settings. These drive the
| protected player's deterrence/detection behaviour (fullscreen, context-menu,
| copy/paste/print blocking, tab/blur detection, watermarking).
|
| IMPORTANT: These are CLIENT-side deterrents that a determined user can
| bypass (browser JavaScript cannot prevent OS-level screenshots or screen
| recording). They are NOT a security boundary. The real boundary is
| server-side authorization: a student may only obtain a playback reference
| after passing every access rule, and only for a short-lived session.
|
*/

return [

    // How long a video playback session stays valid before it must be renewed.
    'playback_session_ttl_minutes' => 30,

    // Client-side deterrence / detection flags returned to the protected player.
    'protection' => [
        'prevent_download' => true,
        'prevent_context_menu' => true,
        'prevent_copy' => true,
        'prevent_paste' => true,
        'prevent_keyboard_shortcuts' => true,
        'prevent_print' => true,
        'detect_tab_switch' => true,
        'detect_window_blur' => true,
        'fullscreen_required' => true,
        'watermark_enabled' => true,
    ],

    // Dynamic watermark settings. The rendered text is assembled per-session and
    // is NOT configured here; only the layout/handling policy is.
    'watermark' => [
        'enabled' => true,
        // Rotate the watermark position periodically to frustrate simple crops.
        'rotate_interval_seconds' => 8,
        // Render as a repeating diagonal overlay.
        'repeating' => true,
        'opacity' => 0.18,
    ],

];
