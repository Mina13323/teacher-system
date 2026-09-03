<?php

/*
|--------------------------------------------------------------------------
| Exam Integrity / Anti-Cheat Configuration
|--------------------------------------------------------------------------
|
| Central, deterministic configuration for the exam integrity system.
| Risk points and severity are assigned here, never scattered in the codebase,
| and are used to compute an attempt's total risk score and integrity status.
|
| Anti-cheat signals are indicators of suspicious activity, NOT definitive
| proof of cheating. The final academic decision remains a teacher/admin choice.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Event type -> risk points
    |--------------------------------------------------------------------------
    |
    | Baseline risk contribution for each recorded integrity event. The backend
    | (never the client) assigns these values. Ignored / deduplicated events
    | always contribute 0.
    |
    */
    'risk_points' => [
        'WINDOW_BLUR' => 1,
        'TAB_SWITCH' => 2,
        'FULLSCREEN_ENTER' => 0,
        'FULLSCREEN_EXIT' => 2,
        'COPY_ATTEMPT' => 2,
        'PASTE_ATTEMPT' => 2,
        'CUT_ATTEMPT' => 2,
        'CONTEXT_MENU_ATTEMPT' => 1,
        'KEYBOARD_SHORTCUT' => 2,
        'WINDOW_FOCUS' => 0,
        // NOTE: MULTIPLE_SUSPICIOUS_EVENTS is intentionally absent here. It is a
        // server-derived CONDITION, not a client-submittable, risk-scored event.
        // Adding it here would allow double-counting (individual event risk plus
        // a synthetic summary of the same evidence), which the design forbids.
    ],

    /*
    |--------------------------------------------------------------------------
    | Event type -> severity
    |--------------------------------------------------------------------------
    |
    | Severity describes the integrity event, it does NOT directly declare
    | cheating. HIGH is reserved for events that are very strong indicators of
    | deliberate circumvention; most events are LOW or MEDIUM.
    |
    */
    'severity' => [
        'WINDOW_BLUR' => 'low',
        'WINDOW_FOCUS' => 'low',
        'TAB_SWITCH' => 'medium',
        'FULLSCREEN_ENTER' => 'low',
        'FULLSCREEN_EXIT' => 'medium',
        'COPY_ATTEMPT' => 'medium',
        'PASTE_ATTEMPT' => 'medium',
        'CUT_ATTEMPT' => 'medium',
        'CONTEXT_MENU_ATTEMPT' => 'low',
        'KEYBOARD_SHORTCUT' => 'medium',
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrity status thresholds
    |--------------------------------------------------------------------------
    |
    | Total risk score used to derive the automatic integrity status.
    |   risk <= 0                      -> NORMAL
    |   risk <  monitoring             -> NORMAL
    |   monitoring <= risk < flagged   -> MONITORING
    |   risk >= flagged                -> FLAGGED
    |
    */
    'thresholds' => [
        'monitoring' => 3,
        'flagged' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multiple-suspicious-events derivation
    |--------------------------------------------------------------------------
    |
    | An attempt is treated as "multiple suspicious events" when at least this
    | many distinct, risk-bearing event types have been recorded. This is a
    | server-derived CONDITION only — it does NOT add any synthetic risk, so the
    | same evidence is never double-counted.
    |
    */
    'multiple_suspicious_min_event_types' => 2,

    /*
    |--------------------------------------------------------------------------
    | Default integrity settings applied to a new exam
    |--------------------------------------------------------------------------
    |
    | Unlike prevention flags, tab-switch and window-blur detection are on by
    | default (a common LMS behavior); copy/paste/context-menu/shortcut
    | prevention and fullscreen requirement are off until a teacher opts in.
    | A teacher may configure these per exam; each attempt then freezes the
    | values that applied when it started.
    |
    */
    'defaults' => [
        'fullscreen_required' => false,
        'prevent_copy' => false,
        'prevent_paste' => false,
        'prevent_context_menu' => false,
        'detect_tab_switch' => true,
        'detect_window_blur' => true,
        'detect_keyboard_shortcuts' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mapping: event type -> the protected feature that gates it
    |--------------------------------------------------------------------------
    |
    | Each raw integrity event is only "active" when the corresponding feature
    | is enabled on the frozen attempt settings. `null` means the event is
    | always allowed (it is a derived/summary event).
    |
    */
    'event_settings' => [
        'TAB_SWITCH' => 'detect_tab_switch',
        'WINDOW_BLUR' => 'detect_window_blur',
        'WINDOW_FOCUS' => 'detect_window_blur',
        'FULLSCREEN_ENTER' => 'fullscreen_required',
        'FULLSCREEN_EXIT' => 'fullscreen_required',
        'COPY_ATTEMPT' => 'prevent_copy',
        'PASTE_ATTEMPT' => 'prevent_paste',
        'CUT_ATTEMPT' => 'prevent_copy',
        'CONTEXT_MENU_ATTEMPT' => 'prevent_context_menu',
        'KEYBOARD_SHORTCUT' => 'detect_keyboard_shortcuts',
        // MULTIPLE_SUSPICIOUS_EVENTS is server-derived and not client-submittable,
        // so it has no gating setting. It is never recorded as an event row.
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication window (seconds)
    |--------------------------------------------------------------------------
    |
    | Identical event types reported for the same attempt within this window are
    | collapsed (the first contributes risk, subsequent duplicates are
    | acknowledged but not stored). This prevents a malicious client from
    | inflating the score by flooding a single repeated event.
    |
    */
    'dedup_window_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | Event recording rate limit
    |--------------------------------------------------------------------------
    |
    | Limits how many integrity events a single user may report per minute via
    | the student recording endpoint. High enough for legitimate browser
    | visibility/focus events, low enough to prevent flooding.
    |
    */
    'rate_limit' => [
        'per_minute' => 60,
    ],

];
