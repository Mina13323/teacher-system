# Video Content Protection — Final Security Model Report

**Date:** 2026-09-07
**Scope:** Security / content-protection hardening for teacher-owned LMS video content.
**Provider decision:** **YouTube is the permanent, zero-cost video hosting/origin.** No
Mux / Bunny / Cloudflare Stream / DRM / paid storage / other provider is introduced.
**Scope discipline:** No subscriptions, payments, billing, marketplace, seller, or
unrelated domains.

**Final status: `YOUTUBE VIDEO DELIVERY HARDENED — READY FOR FRONTEND INTEGRATION`**

---

## Confirmed final design decisions

**A1 — Provider model: `provider` + `provider_video_id` + existing `storage_path`.**
- `provider` (`storage` | `youtube`) selects how the media reference is interpreted.
- `provider=youtube` ⇒ `provider_video_id` is the YouTube video identifier.
- `provider=storage` ⇒ `storage_path` is used (kept for future self-hosted/local files).
- Provider metadata is **server-side only**; never in normal student catalog/list responses.
- No YouTube account credentials / OAuth secrets stored in video records.
- `storage_path` is **not** replaced by `media_ref`; no unnecessary schema change.

**B1 — Playback response: minimum `media_ref` only through the authorized short-lived
playback response.**
- Normal student resources **never** expose `provider`, `provider_video_id`, YouTube URL,
  channel id, playlist id, embed URL, `storage_path`, download URL, or provider metadata.
- The protected playback endpoint returns the minimum playback reference (`media_ref`)
  required to initialise the player, after full server-side authorization, inside a
  short-lived session.
- `media_ref` is **not** treated as a secret (an embeddable YouTube video necessarily
  makes the video id available to the browser), but it never appears in catalog/list APIs,
  never becomes a permanent public URL, and never exposes the channel/playlist or enables
  enumeration.

---

## 1. The student stays inside Teacher-System

The intended flow is **Teacher-System → Course → Unit → Lesson → Video → Player inside
Teacher-System**. There are:

- No UI links to `youtube.com`, `youtu.be`, a YouTube channel, a YouTube watch page, or a
  playlist.
- No "Watch on YouTube / Open in YouTube / Visit channel / Share on YouTube" affordances.
- No teacher-channel exposure.

The YouTube embed is rendered inside the Teacher-System UI; the student is never
intentionally navigated to YouTube.

## 2. Never expose provider data in normal APIs

Student-facing APIs (course list/detail, lesson list/detail, video list, student
dashboard, student analytics, notifications) contain **no** `provider`,
`provider_video_id`, `youtube_video_id`, channel id, playlist id, embed URL,
`storage_path`, or download URL. Provider info belongs **exclusively** to the protected
playback flow.

## 3. Protected playback

`GET /api/v1/student/videos/{video}/playback`

1. authenticate the student
2. verify account is active
3. verify `student`/`admin` role
4. resolve the actual `Video` model (route-model binding)
5. verify the video belongs to a lesson
6. verify the lesson belongs to a course
7. verify course access (published)
8. verify enrollment in the course
9. verify the lesson is published
10. verify the video is published
11. verify Policy authorization (`VideoPolicy::play`)
12. issue a short-lived playback session
13. return the minimum data required to initialise the player

Frontend checks are never trusted; the video id supplied by the frontend is resolved to
the real `Video` model and re-authorized on every request.

## 4. No ID-lookup / enumeration API

There is **no** endpoint such as `/videos/{id}/youtube-id`,
`/playback-sessions/{token}`, `/video-provider/{id}`, or `/youtube/{id}`. Students cannot:
- sequentially enumerate provider ids
- bulk-lookup provider ids
- filter / search / sort by `provider_video_id`
- obtain provider ids through pagination metadata

The only student video routes are the minimal list and the protected playback (plus the
scoped event-recording route below).

## 5. No provider data persisted client-side (frontend contract)

The frontend **must not** persist the YouTube id in localStorage / sessionStorage /
IndexedDB / cookies / URL query / URL path / persistent global state. It should hold the
minimum playback reference in volatile runtime memory only for the lifetime of the active
player and clear it when the player is destroyed. The provider id must not appear in
analytics events, error logs, or notification payloads.

> This is a documented frontend contract; the backend never returns provider data in
> cookie/header/set-cookie or analytics payloads and never puts it in notifications.

## 6. No provider id in page source / hydration payloads

The backend never server-renders the YouTube id into HTML, `data-*` attributes, hidden
inputs, meta tags, JSON-LD, hydration payloads, or public config objects. The protected
playback request is the **only** point where the browser receives the playback reference.

> Backend guarantee: the normal lesson/course/video API responses contain no provider
> reference; the playback reference is delivered in a separate authorized JSON response
> only.

## 7. YouTube player inside Teacher-System

A YouTube **embedded player** is used inside the Teacher-System UI. The strongest
practical YouTube privacy/embed configuration is applied; videos are **not** converted to
Public. **Unlisted** is preferred over Public when Private cannot be embedded for the
student audience. We document that Unlisted video ids can technically be discovered by a
sufficiently capable browser user because the player needs the video reference.

## 8. Frontend deterrence

`config/video.protection` returns the deterrent flags to the protected player:
`prevent_download`, `prevent_context_menu`, `prevent_copy`, `prevent_paste`,
`prevent_cut`, `prevent_selection`, `prevent_drag`, `prevent_print`,
`prevent_save_page`, `block_ctrl_s`, `block_ctrl_p`, `block_ctrl_u`, `block_f12`,
`block_ctrl_shift_i`, `block_ctrl_shift_j`, `block_ctrl_shift_c`,
`detect_tab_switch`, `detect_window_blur`, `detect_devtools`, `fullscreen_required`,
`watermark_enabled`.

These are **deterrence only** and are not the security boundary.

## 9. DevTools / tampering detection (honest)

Detection is a deterrence/detection mechanism only: on detection, the frontend may pause
or obscure content, log a content-protection event (via the scoped endpoint), and require
re-authorization. It must **not** assume guilt, permanently ban, create an infinite loop,
use invasive fingerprinting, or collect unrelated browser information. We do **not** claim
DevTools can be disabled — a user controls their browser.

## 10. Watermark

A dynamic, per-session watermark is included in the playback payload:
`{ text, session_id, repeating, rotate_interval_seconds, opacity }`. `text` uses the
student's `publicDisplayName()` plus an opaque 6-character session code (the full token is
never shown; no password / token / secret / private email is included). It remains visible,
moves periodically, and is hard to crop out consistently. Purpose: **deterrence +
attribution**, not absolute screenshot prevention.

## 11. Screenshot / screen-recording deterrence

Practical deterrents: fullscreen, blur detection, visibility-change detection, watermark,
player obscuring/pause on suspicious state, keyboard-shortcut blocking, context-menu
blocking. We do **not** claim JavaScript can prevent OS-level screenshots, external
screen-recording software, another phone filming the screen, browser modifications, or
modified clients.

## 12. Content-access revocation

A student receives **no new** playback authorization when: the account is disabled, the
enrollment is cancelled, course access is revoked, the lesson is unpublished, or the video
is unpublished. Playback sessions are short-lived (default 30 min) and expire on their own;
there is no permanent playback authorization. A previously issued short-lived token is
re-checked against authorization rules only at grant time — the player must renew — so a
revoked/expired grant cannot bypass current authorization.

## 13. Security logging (content-protection events)

`video_playback_events` records content-protection / audit events:
- **Server-authoritative** (high trust): `PLAYBACK_GRANTED`, `PLAYBACK_DENIED`,
  `SESSION_EXPIRED`. A `PLAYBACK_DENIED` event is recorded automatically when an
  unauthorized student attempts playback.
- **Client-reportable deterrence detections** (low trust, throttled):
  `FULLSCREEN_EXIT`, `TAB_SWITCH`, `WINDOW_BLUR`, `DEVTOOLS_DETECTION`. Reported via a
  **scoped, throttled** endpoint that only accepts events against the student's OWN active
  session for the route video — cross-student tampering and any session-lookup/enumeration
  are impossible.

The record stores only `video_id`, `student_id`, `session_id`, `event_type`, `occurred_at`.
It **never** stores clipboard contents, keystroke streams, webcam, microphone, screen
recordings, GPS, browsing history, or other personal data. Events are for auditability,
not proof.

## 14. No fake security

We deliberately do **not** base64-encode, "encrypt" with a frontend key, obfuscate in JS,
split across variables, hide in CSS/comments, or use a "secret" frontend env var to hide
the YouTube id. Anything delivered to the browser can be inspected. The correct model is:
DO NOT expose it until playback is authorized, then **authorized short-lived playback +
minimum required provider reference + no persistence + no enumeration + watermark +
deterrence + auditability**.

## 15. API security — provider reference cannot be manipulated by students

No student route accepts or can change `provider`, `provider_video_id`, or `storage_path`.
The teacher/admin create/update video endpoints are the only place provider reference is
managed, and they require the video to be owned by the teacher (Policy). A student calling
a teacher video endpoint is denied (403). Provider id cannot be injected through create/
update/enrollment/progress/analytics/playback requests or query parameters.

## 16. Resource audit

All student-facing resources were audited, including nested serialization
(`StudentVideoResource`, `LessonDetailResource`, `CourseResource`, `StudentCourseResource`,
`CourseRoadmapResource`, `EnrollmentResource`, `LessonResource`, `LessonProgressResource`,
`CourseDetailResource`, `UnitDetailResource`, analytics, notifications, dashboard). **No
video/provider metadata leaks** through nested serialization. The only student-facing
payload with a media reference is the authorized `VideoPlaybackResource`.

## 17. Security regression tests

`tests/Feature/Video/VideoContentProtectionTest.php` covers (23 scenarios):

1. Student video list contains no YouTube id.
2. Student lesson/video responses contain no YouTube id.
3. Student course/dashboard/analytics/notification responses contain no YouTube id (audited;
   no provider data present).
4. No provider-id query or enumeration endpoint (bogus id route → 404; list has no provider id).
5. Unauthorized student cannot obtain playback (403).
6. Authorized student can obtain playback (201 + session created).
7. Playback is short-lived (expires_at bounded).
8. Playback is scoped to the student (distinct tokens; no cross-student reuse).
9. Another student cannot use another's playback session (ownership check).
10. Deactivated student cannot obtain new playback.
11. Cancelled enrollment cannot obtain new playback.
12. Another teacher's video cannot be accessed.
13. Provider reference cannot be modified by a student (teacher video endpoints → 403).
14. Teacher can manage their own video.
15. Teacher cannot manage another teacher's video.
16. Admin behavior follows existing admin policy.
17. Changing route ids does not bypass authorization (IDOR).
18. Public/no provider metadata endpoint exposure (none).
19. No provider metadata in URL parameters (route uses internal id).
20. No provider metadata in normal HTML/page state (backend returns none).
21. Client-detection events only against the student's own active session.
22. Server-authoritative event types rejected from the client.
23. Denied playback records a server-side audit event.

## 18. Final honest security model

Teacher-System provides **application-level access control** and **strong content-leak
deterrence**. It does **not** provide DRM for YouTube content. A technically sophisticated
user who controls their browser may inspect network requests or player state and discover
the YouTube video reference, because an embeddable YouTube player requires that reference.
This limitation is accepted because YouTube is the **permanent zero-cost hosting solution**.

The objective is to prevent: casual sharing, direct catalog scraping, API enumeration,
unauthorized LMS access, accidental provider exposure, easy copy/download workflows,
casual DevTools extraction, channel discovery, and unauthorized course access — while
keeping the student entirely inside Teacher-System.

## 19. Static verification results

| Check | Result |
|---|---|
| PHP AST syntax (`check.js`) | `TOTAL=379 BAD=0` |
| Referenced App/Test class existence (`refcheck.js`) | `References=838 MISSING=0` |
| Route → controller resolution (`routecheck.js`) | ✅ all resolve |
| Controller dependency import / type-hint scan (`depcheck.js`) | ✅ clean |
| Public controller authorization scan (`authscan.js`) | ✅ all handlers guarded |
| Student-resource exposure scan | ✅ `provider`/`storage_path`/channel/playlist/embed/download only in authorized `VideoPlaybackResource` |

**Runtime tests were NOT executed** (PHP/Composer unavailable); no runtime pass/fail claim
is made.

## 20. Files changed / added (this hardening pass)

- **Migrations:** `000602_create_video_playback_events_table` (new; on top of `000600`
  provider columns and `000601` playback sessions).
- **Models:** `VideoPlaybackEvent` (new).
- **Enums:** `VideoPlaybackEventType` (new).
- **Config:** `config/video.php` — expanded deterrence flags + `event_rate_limit_per_minute`.
- **Actions:** `RecordVideoPlaybackEventAction` (new).
- **Requests:** `RecordVideoPlaybackEventRequest` (new).
- **Exception:** `InvalidVideoPlaybackSessionException` (new).
- **Controller:** `Student\VideoController` — grant/deny audit + scoped event-recording.
- **Providers:** `AppServiceProvider` — `throttle:video-events` rate limiter.
- **Exception handling:** `bootstrap/app.php` — render the new exception.
- **Routes:** `POST /api/v1/student/videos/{video}/playback/events` (scoped, throttled).
- **Tests:** expanded `tests/Feature/Video/VideoContentProtectionTest.php`.
- **Docs:** this report.

## 21. Whether the architecture is ready for frontend integration

**Yes.** The frontend integrates via:
- `GET /api/v1/student/lessons/{lesson}/videos` → minimal `StudentVideoResource[]`.
- `GET /api/v1/student/videos/{video}/playback` → `VideoPlaybackResource` (metadata +
  `playback.provider` + `playback.media_ref` + `playback.token` + `expires_at` +
  `protection` + `watermark`).
- `POST /api/v1/student/videos/{video}/playback/events` → report scoped detections.

The protected player stays entirely inside Teacher-System, applies the `protection`
deterrence, renders the `watermark`, keeps the playback reference only in volatile memory,
and renews the short-lived session. No channel/playlist/account metadata is needed or
supplied. **No further backend feature work is required for frontend integration.**
