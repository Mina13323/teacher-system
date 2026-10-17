# B2 + B3: request consolidation (after B1)

Baseline: `0574549` (B1) on top of `7473784`. No backend behaviour change for exams, grading, deadlines,
answer persistence, locking, integrity or attempt lifecycle. Nothing was deployed; Production untouched.
Savings below are **code-based estimates**, not measurements.

## B2: unread notification count

### Audit (at `0574549`)

- Endpoint: `GET /notifications/unread-count` (`NotificationController::unreadCount`).
- Frontend callers: **only** `ExamTake.vue`, after a submit (60–180 s later, random) and after an integrity
  termination. Nothing requests the count at boot, login or layout mount: the portal badge
  (`PortalShell.vue`, `notifications.unread`) **stayed 0 until an exam result** (an existing bug).
- Mark-read / mark-all-read: the store lowers / clears the badge locally after the server call.
- Push notifications: no effect on the count in the client.
- Logout / user switch: the notifications store was **never cleared**, so a second user in the same
  tab could see the first user's badge and inbox items until refreshed (an existing bug).
- `/auth/me`: called once per app boot (router guard) and by the reconnect screen; not cached
  server-side; the service worker uses `NetworkOnly` for `/api/*`. The token identifies the user, so a
  count in it is that user's own.
- The only notification a submit or termination can add is "result available", sent synchronously by
  `GradeExamAttemptAction` **only when the result is published at once** (`grades_published`).

So the proposal's premise (a separate count request on every page) does not hold here: there was one
request per exam submit, and the badge was otherwise never loaded.

### Change

- `User::unreadNotificationCount()` is the single count implementation (used by the old endpoint too).
- `GET /auth/me` adds `unread_count` (one indexed `COUNT` in the same request). It is added in
  `AuthController`, **not** `UserResource`, so user lists never count per row.
- `POST /auth/login` adds `unread_count` the same way, so the badge is right right after signing in.
- Store: `setUnread()` adopts the count from `/auth/me` and login; `reset()` on login, logout, a 401 and
  when `/auth/me` answers for a different user; a count request in flight across a reset is dropped.
- `ExamTake.vue`: the badge refresh after a submit / termination is sent **only when the result was
  published** (the only case where a notification was added). The old endpoint and route stay.

### Estimated savings (B2)

| Where | Before | After |
|---|---|---|
| App boot / login | 0 requests, badge wrong (0) | 0 extra requests, badge right (+1 COUNT inside an existing request) |
| Exam submit, result **not** published at once (manual grading / delayed results) | 1 request per student | 0 |
| Exam submit, result published at once | 1 request per student | 1 (unchanged) |

For a manual-grading exam: ~50 / 100 / 500 requests avoided per sitting at 50 / 100 / 500 students,
spread over the 60–180 s window after the submits (≈0.4 / 0.8 / 4.2 per second during it). For an
auto-published exam: none. Compared with fixing the boot-badge bug the naive way (a count request on every
boot), it also avoids one request per app boot/login.

## B3: student lesson page

### Audit (at `0574549`)

- `Lesson.vue` sent 3 parallel requests: `GET /student/lessons/{id}` (lesson + attachments,
  `LessonPolicy::access`), `GET …/progress` (enrollment + published only), `GET …/videos` (same policy).
- `LessonExtras.vue` (shown only for lessons **without videos**) sent 3 more on mount: notes, Q&A and
  **all** bookmarks (paginated, so the lesson's bookmark could be missed past page 1). There were no tabs.
- Playback references come only from `GET /student/videos/{id}/playback` (`ProtectedPlayer`);
  `StudentVideoResource` carries metadata only.
- The route component is reused when only `:id` changes; the old page read the id once and never reloaded.
- Finding (pre-existing, out of scope, filed separately): `GET …/progress` returns the full lesson
  (including `content`) under weaker checks than `LessonPolicy::access`; verified that a roadmap-locked
  lesson's content is readable through it. The new page does not use that endpoint.

### Change

- `GET /student/lessons/{id}?include=progress,videos,bookmark` (any subset) adds, behind the same
  `LessonPolicy::access`: the student's progress (same as `…/progress` minus the nested lesson), the
  published video list (same as `…/videos`, metadata only), and the student's own lesson-level bookmark
  (`{ id }` or null). Without `include` the response is byte-for-byte as before; unknown names are ignored.
  Shared helpers (`LessonProgress::forStudentLesson`, `Lesson::publishedVideos`) avoid duplicated queries.
  Query count is constant in the number of videos (tested).
- `Lesson.vue` makes that one request (`utils/lessonPage.js`), reloads when the lesson id changes,
  drops responses/errors for a lesson the page has left, clears the previous lesson on error, and falls
  back to the separate endpoints if the server returns the plain lesson (older backend).
- `LessonExtras.vue`: Notes and Q&A are tabs loaded on first open and kept (`utils/lazyTabs.js`:
  no duplicate requests on rapid clicks or tab switching; only the changed tab reloads after an action;
  failed loads retry on the next open; nothing applied after unmount). The bookmark button uses the
  bookmark from the lesson response (no bookmarks request); it looks it up only against an older server.
  The component is keyed by lesson, so a lesson change starts fresh. Placement is unchanged.
- Unchanged: playback flow, attachment download, mark-complete (`PUT …/progress`), all routes.

### Estimated savings (B3), per lesson opened

| Lesson | Before | After | Avoided |
|---|---|---|---|
| With videos | 3 | 1 | 2 |
| Without videos, no tab opened | 6 | 1 | 5 |
| Without videos, both tabs opened | 6 | 3 | 3 |

At 50 / 100 / 500 students each opening one lesson with videos: ~100 / 200 / 1,000 requests avoided
(up to 250 / 500 / 2,500 for lessons without videos). The combined request runs a few more queries than
the plain lesson read (≤ 3, tested) but replaces three requests, each with its own connection and token lookup.

## Combined with B1 (estimates)

| | Unit | Avoided |
|---|---|---|
| B1 | per actively answering exam student per hour | ~19 DB status checks (become DB-free `/time` pings; HTTP count unchanged) |
| B2 | per student per exam sitting with non-immediate results | 1 request |
| B3 | per lesson opened | 2–5 requests |

Example, 500 students: a 1-hour exam with manual grading ≈ 9,500 (B1) + 500 (B2) fewer DB-touching
requests; 500 lesson openings ≈ 1,000–2,500 (B3). **No reduction in MySQL connections has been
measured.** The SQLSTATE 2002 / `Operation not permitted` failures remain unresolved; B1–B3 are not a fix.

## Staging-only validation plan (not executed)

Deploy B1+B2+B3 to **Staging only**, with the established process (build with
`npm run build`, the repo's staging deploy steps, `php artisan migrate --pretend` shows no migrations).

1. **B1:** steps in `docs/B1_Reduce_Redundant_Heartbeats.md` §4A (heartbeat ~6 min while answering,
   ~2 min idle, reconnect, refresh, deadline, termination, integrity review, DB answers = acknowledgements).
2. **B2 badge + isolation:** user A with 3 unread → badge 3 on first screen, with no
   `/notifications/unread-count` call in DevTools; read one → 2; read all → 0; log out, log in as B (0
   unread) in the same tab → 0, A's inbox not visible; manual-grading exam submit → no unread-count call;
   auto-published exam submit → one call 60–180 s later and the badge +1.
3. **B3 lesson page:** lesson with videos → one `GET /student/lessons/{id}?include=…`; play a video
   (playback request + session as before); mark complete persists after reload; attachment download works.
   Lesson without videos → no notes/Q&A/bookmarks request until a tab is opened; each tab loads once;
   add a note / ask a question reloads only that tab; bookmark toggles and survives reload. Non-enrolled
   student, unpublished lesson, roadmap-locked lesson → error state, no content. Navigate lesson → lesson
   (back/forward) shows the right lesson; refresh mid-page works.
4. **Regression:** teacher/assistant/admin portals load, notifications inbox, exam start/answer/submit,
   video protection events, `php artisan test` and `npx vitest run` on the deployed commit.
5. **k6** (`load-tests/exam-realistic.js`, operator-run): `STAGE=50` first. Only after a clean 50 run,
   `STAGE=100`. Optional comparison: `B1_SAVE_DEFERS_STATUS=0` at 50.
6. **Monitor:** `storage/logs`, `metrics:requests` (DB connections/s, per route), SQLSTATE 2002 /
   `Operation not permitted` / connection refused, HTTP 503 (`service_busy`) / 504, k6 transport errors,
   lost answers (script aborts), duplicate attempts/finalizations, 401s, p95/p99 vs. README thresholds.
7. **Stop immediately** on any SQLSTATE 2002 / connection refusal (do not repeat or raise the step until
   the hosting/DB cause is understood); also on 503/504 bursts, any lost answer, duplicate attempt,
   grading change or unexpected 401, or p95 above threshold.
