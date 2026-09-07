# Video Content Protection — Implementation Report

**Date:** 2026-09-07
**Scope:** Security / content-protection hardening for teacher-owned LMS video content.
**Scope discipline:** No subscriptions, payments, billing, marketplace, seller, or
unrelated domains were introduced. No broad rewrite — focused, minimal additions.

**Final status: `VIDEO CONTENT PROTECTION READY FOR FRONTEND INTEGRATION — STATIC
VERIFICATION`**

---

## Confirmed final design decisions

**A1 — Provider model: `provider` + `provider_video_id` + existing `storage_path`.**
- `provider` (`storage` | `youtube`) selects how the media reference is interpreted.
- `provider=youtube` ⇒ `provider_video_id` is the YouTube video identifier.
- `provider=storage` ⇒ `storage_path` is used (self-hosted/local future proofing).
- Provider metadata is **server-side only**; never in normal student catalog/list responses.
- No YouTube account credentials / OAuth secrets stored in video records.
- `storage_path` is **not** replaced by `media_ref`; no unnecessary schema change.

**B1 — Playback response: minimum `media_ref` only through the authorized
short-lived playback response.**
- Normal student video/list/catalog resources **never** expose `provider`,
  `provider_video_id`, YouTube URL, channel id, playlist id, embed URL, `storage_path`,
  download URL, or provider metadata.
- The protected playback endpoint may return the minimum playback reference (`media_ref`)
  required to initialise the player, after full server-side authorization, inside a
  short-lived session.
- `media_ref` is **not** treated as a secret (an embeddable YouTube video necessarily
  makes the video id available to the browser), but it must not appear in catalog/list
  APIs, must not become a permanent public URL, and must not expose the channel/playlist
  or enable enumeration.

---

## 1. What video-security functionality was implemented

- **Provider abstraction.** `Video` now carries a `provider` (`storage` | `youtube`)
  enum and a server-side `provider_video_id`. The existing `storage_path` remains as a
  provider-agnostic media reference and stays server-only.
- **Protected playback endpoint.** `GET /api/v1/student/videos/{video}/playback` issues a
  short-lived playback session only after full server-side authorization. It is the only
  student-facing surface that yields a playable media reference.
- **Video list endpoint.** `GET /api/v1/student/lessons/{lesson}/videos` lists published
  videos of an authorized lesson with **minimal** metadata only (no provider info).
- **Authorization policy.** `VideoPolicy::play` and `LessonPolicy::access` enforce the
  complete access chain: active account, student/admin, video/lesson/course published,
  student enrolled in the video's course. Re-checked on **every** request.
- **Short-lived playback sessions.** `video_playback_sessions` table + `VideoPlaybackSession`
  model (token, `expires_at`, `revoked_at`). Sessions expire on their own; new ones are
  issued only after re-authorization.
- **Content-protection config.** `config/video.php` centralises deterrence/detection flags,
  playback session TTL, and watermark policy.
- **Resource separation.** `StudentVideoResource` (minimal), staff `VideoResource` (adds
  provider/storage for staff only), and `VideoPlaybackResource` (the protected payload).

## 2. Student-facing fields removed / restricted

Student-facing `StudentVideoResource` returns only: `id`, `lesson_id`, `title`,
`duration`, `position`. It **never** returns `provider`, `provider_video_id`,
`storage_path`, channel/playlist id, embed URL, or any download/provider URL.

The public course catalog and student roadmap now route videos through
`StudentVideoResource` (non-staff) or `VideoResource` (staff only), so provider/storage
metadata only ever appears for authenticated teachers/admins (and only via teacher
endpoints / staff lesson detail).

## 3. Protected playback flow

```
GET /api/v1/student/videos/{video}/playback   (auth:sanctum)
  → VideoPolicy::play(video)          // active + roles + enroll + published + ownership
      ✓ account active        ✓ student/admin
      ✓ course published      ✓ lesson published      ✓ video published
      ✓ enrolled in the video's course
  → CreatePlaybackSessionAction  // short-lived session (token + expires_at)
  → VideoPlaybackResource        // video metadata + provider name + media_ref +
                                 // session token/expiry + protection + watermark
```

A student cannot reach playback by swapping the video/lesson/course id — each request is
re-authorized against the actual `video` route-model. There is no token lookup endpoint,
so no cross-student session read is possible.

## 4. Screenshot / screen-recording deterrence

`config/video.protection` returns client-side deterrence/detection flags to the
protected player: `prevent_download`, `prevent_context_menu`, `prevent_copy`,
`prevent_paste`, `prevent_keyboard_shortcuts`, `prevent_print`, `detect_tab_switch`,
`detect_window_blur`, `fullscreen_required`, `watermark_enabled`.

**These are deterrents, not security.** Browser JavaScript cannot prevent OS-level
screenshots or screen recording. The model is PREVENT (where possible) + DETECT + DETER +
REVOKE/RESTRICT (server re-authorization), never "perfect DRM."

## 5. Watermarking

A **dynamic, per-session watermark** is included in the playback payload:
`{ text, session_id, repeating, rotate_interval_seconds, opacity }`. The `text` uses the
student's `publicDisplayName()` plus an opaque 6-character session code (the full token
is never shown; no password/secret/email is included). The frontend renders it as a
repeating, rotating overlay for leak attribution.

## 6. Authorization rules protecting playback

Every playback request requires ALL of:

1. Authenticated (`auth:sanctum`).
2. Active account (`is_active`).
3. `student` or `admin` role.
4. Video `is_published`.
5. Lesson `is_published`.
6. Course status `published`.
7. Student actively enrolled in the video's course.
8. (Implied) the video belongs to that lesson/course.

These are enforced server-side in `VideoPolicy::play`; nothing relies on frontend checks.

## 7. YouTube limitations (documented honestly)

- **Private** YouTube videos CANNOT be embedded for arbitrary app users — only the
  account owner (and explicitly invited Google accounts) can view them. The LMS would
  **not** be able to embed a Private video for a student.
- **Unlisted** YouTube videos CAN be embedded but the browser necessarily receives the
  video id (and the player communicates with YouTube), so the id is available to the
  viewer. This is inherent to any embeddable YouTube video and cannot be prevented by our
  API.
- **Public** videos are discoverable and expose channel links — we intentionally do NOT
  surface any channel/playlist/account metadata.

## 8. Does the current YouTube architecture satisfy the business requirement?

**Application-level layer: YES.** Students can only discover/watch a video they are
authorized to access; they cannot enumerate the teacher's channel/playlist/videos or
obtain raw provider URLs/storage paths through our API; every access is re-authorized
server-side and sessions are short-lived.

**Provider-level layer: HONESTLY PARTIAL.** A **Private** YouTube video cannot support
embedded playback for arbitrary students. The practical embeddable option is **Unlisted**
(which leaks the video id to the browser). This is a browser/YouTube limitation we are
**not** pretending to solve.

## 9. Changes needed before production

1. **Do not** attempt to embed **Private** YouTube videos for students (impossible). Use
   the video's chosen privacy mode that supports embedding, and record that decision
   per-video.
2. **Provider configuration per video:** set `provider=youtube` + `provider_video_id`
   (or rely on the `storage` provider).
3. **Embedding restrictions:** where YouTube supports it, restrict embedding to our
   domain and disable related-video/channel surfaces in the player config.
4. **Strongest provider security:** for genuinely protected/DRM/downloable content, move
   to a provider with **signed playback URLs / domain restrictions / tokenized delivery /
   anti-download / authenticated playback**. The current architecture is provider-agnostic
   and ready for that swap without changing the student-facing contract. **No paid
   provider was added** (out of scope).
5. **Runtime verification** (PHP/Composer unavailable here) before deployment.

## 10. Updated static verification results

| Check | Result |
|---|---|
| PHP AST syntax (`check.js`) | `TOTAL=372 BAD=0` |
| Referenced App/Test class existence (`refcheck.js`) | `References=825 MISSING=0` |
| Route → controller resolution (`routecheck.js`) | ✅ all resolve |
| Controller dependency import / type-hint scan (`depcheck.js`) | ✅ clean |
| Public controller authorization scan (`authscan.js`) | ✅ no guardless handlers |
| Student-resource exposure scan | ✅ `provider`/`storage_path`/channel/playlist/embed/download only in the authorized `VideoPlaybackResource`; absent from all normal student resources |

**Runtime tests were NOT executed** (PHP/Composer unavailable); no runtime pass/fail claim
is made.

## 11. Security regression tests added

`tests/Feature/Video/VideoContentProtectionTest.php` covers:

1. Authorized student can request playback (with session row created).
2. Unauthenticated user cannot request playback (401).
3. Student cannot access unpublished lesson video list (403).
4. Student video list never exposes provider references (assertNoLeak).
5. Student cannot access another teacher's video.
6. Student cannot access a video from a course they are not enrolled in.
7. Student cannot access unpublished video.
8. Playback sessions scoped to the authenticated student (no cross-student token reuse).
9. Student cannot obtain channel id / provider URL / download / storage path.
10. Deactivated student cannot obtain playback access.
11. Cancelled enrollment cannot obtain playback access.
12. Teacher can manage an owned video (including provider fields).
13. Teacher cannot manage another teacher's video.
14. Admin can view any video (follows existing admin policy).
15. Changing the video id does not bypass authorization (IDOR).

## 12. Files changed / added

- **Migrations:** `000600_add_provider_to_videos_table`, `000601_create_video_playback_sessions_table`.
- **Models:** `Video` (provider, provider_video_id, playbackSessions, providerReference),
  `VideoPlaybackSession` (new).
- **Enums:** `VideoProvider` (`storage` | `youtube`).
- **Config:** `config/video.php` (protection flags, session TTL, watermark policy).
- **Policies:** `VideoPolicy` (+`play`), `LessonPolicy` (+`access`).
- **Actions:** `Video/CreatePlaybackSessionAction` (new), `Video/CreateVideoAction` (provider default).
- **Controllers:** `Student/VideoController` (new: `index`, `playback`).
- **Resources:** `StudentVideoResource` (new), `VideoPlaybackResource` (new), `VideoResource`
  (+provider metadata for staff only), `LessonDetailResource` (context-aware videos).
- **Requests:** `CreateVideoRequest`, `UpdateVideoRequest` (+provider fields).
- **Factories:** `VideoFactory`, `VideoPlaybackSessionFactory` (new).
- **Routes:** `GET /api/v1/student/lessons/{lesson}/videos`,
  `GET /api/v1/student/videos/{video}/playback`.
- **Tests:** `tests/Feature/Video/VideoContentProtectionTest.php` (new).
- **Docs:** `docs/Video_Content_Protection_Report.md`.

## 13. Whether the architecture is ready for frontend integration

**Yes.** The frontend integrates via:
- `GET /api/v1/student/lessons/{lesson}/videos` → minimal `StudentVideoResource[]` for the
  lesson's video list.
- `GET /api/v1/student/videos/{video}/playback` → `VideoPlaybackResource` (metadata +
  `playback.provider` + `playback.media_ref` + `playback.token` + `expires_at` +
  `protection` + `watermark`) to render the protected player.

The protected player uses the returned `provider`/`media_ref`/`token`/`expires_at`,
applies the `protection` deterrence flags, and renders the `watermark`. No channel/playlist/
account metadata is needed or supplied. **No further backend feature work is required for
frontend integration.**

---

## Summary

The student can **watch authorized content** inside Teacher-System, but cannot use the API
to discover the teacher's channel, enumerate videos, obtain raw provider URLs/storage, or
bypass course/lesson authorization. Playback is protected by **defense in depth**:
server authorization + short-lived access + provider restrictions (documented) + fullscreen
+ deterrence + detection + dynamic watermarking + revocation (re-authorization) + audit
via the session record. A browser can never guarantee against screen capture — we do not
claim otherwise.
