<?php

/*
|--------------------------------------------------------------------------
| Video content protection
|--------------------------------------------------------------------------
|
| Application-level video playback protection settings. These drive the
| protected player's deterrence/detection behaviour (fullscreen, context-menu,
| copy/paste/print blocking, keyboard-shortcut blocking, tab/blur/devtools
| detection, watermarking) and the short-lived playback session lifetime.
|
| IMPORTANT: These are CLIENT-side deterrents that a determined user can
| bypass (browser JavaScript cannot prevent OS-level screenshots or screen
| recording, and cannot disable DevTools for a user who controls their own
| browser). They are NOT a security boundary. The real boundary is server-side
| authorization: a student may only obtain a playback reference after passing
| every access rule, and only for a short-lived session.
|
*/

return [

    // How long a video playback session stays valid before it must be renewed.
    'playback_session_ttl_minutes' => 30,

    // Rate limit (per minute) for the client-reported playback protection events.
    'event_rate_limit_per_minute' => 60,

    // Client-side deterrence / detection flags returned to the protected player.
    'protection' => [
        'prevent_download' => true,
        'prevent_context_menu' => true,
        'prevent_copy' => true,
        'prevent_paste' => true,
        'prevent_cut' => true,
        'prevent_selection' => true,
        'prevent_drag' => true,
        'prevent_print' => true,
        'prevent_save_page' => true,
        'block_ctrl_s' => true,
        'block_ctrl_p' => true,
        'block_ctrl_u' => true,
        'block_f12' => true,
        'block_ctrl_shift_i' => true,
        'block_ctrl_shift_j' => true,
        'block_ctrl_shift_c' => true,
        'detect_tab_switch' => true,
        'detect_window_blur' => true,
        'detect_devtools' => true,
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
