# Frontend ↔ Backend Compatibility Report

**Scope:** Teacher-System SPA (Vue 3 + Vite) frontend against the Laravel API (`/api/v1`).
**Date:** 2026-09-07
**Method:** Static verification only. PHP/Composer runtime is unavailable in this sandbox, so runtime/feature tests were **not** executed. All statements below reflect source-level contract verification (routes, controllers, policies, resources, request validation) and a successful production build.

---

## 1. Final Status

> **READY FOR TESTING**

The frontend is fully wired to real backend endpoints (verified end-to-end at the source level), builds successfully, and enforces the agreed role/permission boundaries. The only remaining step is to run the app against a PHP host and perform runtime/feature testing — which cannot be done in this sandbox.

---

## 2. Verification Summary

| Check | Tool | Result |
| --- | --- | --- |
| Frontend production build | `npx vite build` | ✅ `✓ built in 3.56s` |
| Frontend API calls ↔ backend routes | `/tmp/pcheck/apicompat.js` | ✅ 104 unique calls, 0 problems, `MATCHED_ALL=true` |
| Controller auth guards present | `/tmp/pcheck/check.js` | ✅ TOTAL=385 BAD=0 |
| Type-hint dependency resolution | `/tmp/pcheck/depcheck.js` | ✅ NO UNRESOLVED TYPE-HINT DEPENDENCIES |
| Referenced classes resolve | `/tmp/pcheck/refcheck.js` | ✅ 858 referenced, 0 missing |
| Route → controller method resolution | `/tmp/pcheck/routecheck.js` | ✅ ALL ROUTE->CONTROLLER METHODS RESOLVE OK |
| Sensitive-data/auth scan | `/tmp/pcheck/authscan.js` | ✅ clean |
| View method calls ↔ API namespaces | `/tmp/pcheck/feapi.js` | ✅ all real API methods resolve (2 false positives from local store vars) |

> Note: `/tmp/pcheck/apicompat.js` was corrected so a `Route::prefix(...)` is only applied to routes that are actually inside that prefix group's interval. The previous parser mis-attributed the **prefix-less** `notifications` group to the `admin` prefix, producing 4 false "missing" routes; after the fix all notifications routes resolve correctly.

---

## 3. Envelope & Error Contract

- Success envelope: `{ success: true, message, data }`.
- Error envelope: `{ success: false, message, errors? }` (Laravel validation errors under `errors`; 422 → friendly message).
- The API client (`resources/js/api/client.js`) returns `data` directly and normalizes failures to an `ApiError` with `isValidation` / `isForbidden` / `isNotFound` / `isAuthError` flags. `toList()` normalizes paginators and resource collections to `{ items, meta }`.

---

## 4. Role & Permission Matrix

Roles: `admin`, `teacher`, `student`, and the newly added **`assistant`**.

| Capability | admin | teacher | assistant | student |
| --- | --- | --- | --- | --- |
| Teacher/assistant account management | ✅ | ✅ | ❌ | ❌ |
| Course structure (units/lessons/videos), exams, questions/options | ✅ | ✅ | ❌ | ❌ |
| Competition creation/rules/leaderboard/recalculate/disqualify | ✅ | ✅ | ❌ | ❌ |
| Analytics & integrity settings/review | ✅ | ✅ | ❌ | ❌ |
| Student CRUD + activate/deactivate + reset-password | ✅ | ✅ | ✅ | ❌ |
| Course enrollment view/management | ✅ | ✅ | ✅ | ❌ |
| System/teacher oversight (admin portal) | ✅ | ❌ | ❌ | ❌ |
| Learn (enrolled course videos/exams/competitions) | — | — | — | ✅ |
| Self analytics / own profile | — | — | — | ✅ |

### Assistant boundary (decision #36)
Assistants are operational staff under the single main teacher. They get **only** student-management powers, implemented as:
- `StudentPolicy::viewAny` / `view` / `create` / `update` / `manage` — assistant may list, view, create, update, and manage (activate/deactivate/reset-password) students.
- `CoursePolicy::viewAny` / `view` — assistant may read courses (to reach their enrolled students).
- `CoursePolicy::manageEnrollments` — assistant may enrol/unenrol students.
- They do **not** receive `update`/`delete` on courses, nor exam/question/option/competition/analytics/integrity powers.

Frontend enforces the same boundary: the `/assistant` route group has `meta.roles: ['assistant']` and exposes only Dashboard, Students (via reused Teacher views), Enroll, CourseStudents, Notifications, Profile. No exam/course-structure/competition/analytics/integrity navigation is reachable from the Assistant portal.

---

## 5. Portal → Route Map

| Portal | Route prefix | Roles | Views |
| --- | --- | --- | --- |
| Public | `/`, `/courses`, `/courses/:id` | guest | Home, CourseCatalog, CourseShow |
| Auth | `/login`, `/register` | guest | Login, Register |
| Student | `/student` | `student` | Dashboard, MyCourses, CourseShow, Lesson, Exams, ExamShow, ExamTake, Competitions, CompetitionShow, Analytics, Notifications, Profile |
| Teacher | `/teacher` | `teacher`, `admin` | Dashboard, Students, StudentForm, StudentShow, Assistants, AssistantForm, Courses, CourseForm, CourseDetail, ExamDetail, ExamForm, Competitions, CompetitionForm, CompetitionDetail, Analytics, StudentAnalytics, Integrity, IntegrityAttempt, Notifications, Profile |
| Assistant | `/assistant` | `assistant` | Dashboard, Enroll, CourseStudents, Students (Teacher/Students.vue), StudentForm (Teacher/StudentForm.vue), StudentShow (Teacher/StudentShow.vue), Notifications, Profile |
| Admin | `/admin` | `admin` | Dashboard, Teachers, TeacherForm, Students, StudentShow, Notifications, Profile |

> The Assistant portal reuses the Teacher student-management views because the router maps `/assistant/students*` to `Teacher/Students.vue`, `Teacher/StudentForm.vue`, `Teacher/StudentShow.vue`. Those views call only assistant-authorized endpoints (`teacher.students`, `teacher.student`, `teacher.createStudent`, `teacher.updateStudent`, `teacher.activateStudent`, `teacher.deactivateStudent`, `teacher.resetStudentPassword`, `teacher.notifyStudent`, `teacher.courseStudents`, `teacher.enrollStudent`, `teacher.unenrollStudent`). Separate `Assistant/Students.vue` etc. were intentionally **not** created (would be dead code).

---

## 6. Data-Shape Alignment (nested resources)

Laravel resources wrap resource collections as `{ data: [...] }`. The frontend unwraps via `toList()`/computed in these views:

- Teacher/Dashboard — `recent_courses`.
- Teacher/IntegrityAttempt — `events`, `reviews`.
- Teacher/ExamDetail — `questions` → `options`.
- Teacher/CourseDetail — `units` → `lessons` → `videos`.
- Public/CourseShow — `units` → `lessons` → `videos`.
- Student/Dashboard — `courses`, `recently_accessed_lessons`.
- Student/ExamTake — `questions` → `options` (via `normalizeAttempt`).
- Admin/StudentShow — `analytics.courses`.

Fields verified to be **plain arrays** (no unwrap needed, confirmed against backend):
- Student/CourseShow `units` — `StudentCourseResource` sets `units` to the roadmap array produced by `BuildCourseRoadmapAction`.
- Student/Analytics `history`, `competition_results` — `BuildStudentAnalyticsAction` returns arrays.
- Teacher/StudentAnalytics `history`, `competition_results` — same action.

---

## 7. Key Backend Contracts Consumed

**Teacher courses:** `teacher.courses()` (paginated `CourseResource`), `teacher.course(id)` (`CourseDetailResource` with nested units/lessons/videos resource collections + count fields), `teacher.createCourse` / `updateCourse` (title, slug, description, thumbnail, status), `teacher.reorderUnits`, `teacher.reorderLessons`, `teacher.publishLesson`/`unpublishLesson`, `teacher.publishVideo`/`unpublishVideo`, `teacher.courseStudents`, `teacher.enrollStudent`, `teacher.unenrollStudent`.

**Teacher exams:** `teacher.exams(courseId)` → `ExamResource`; `teacher.exam(id)` → `ExamDetailResource` (questions → options); `teacher.createExam` / `updateExam` (title, description, duration_minutes, pass_percentage, max_attempts, shuffle_questions, shuffle_options, show_result_immediately); `teacher.createQuestion` (single_choice + points); `teacher.createOption` (`option_text`, `is_correct`); `teacher.publishExam` / `archiveExam` / `deleteExam`; `teacher.integritySettings` / `updateIntegritySettings`; `teacher.examAttempts`.

**Teacher competitions:** `teacher.competitions()`; `teacher.competition(id)`; `teacher.createCompetition` (title, description, exam_id, starts_at, ends_at, max_participants, scoring_type `in:highest_score,best_attempt`, ranking_type `in:score_desc`); `teacher.updateCompetition` (title, description, starts_at/ends_at, max_participants — **exam_id/scoring_type/ranking_type immutable**); `teacher.publishCompetition`/`archiveCompetition`/`deleteCompetition`; `teacher.competitionParticipants` (`CompetitionParticipantResource`: id, student_id, `student_display_name`, joined_at, status, result); `teacher.competitionLeaderboard` (`LeaderboardResource`: rank, `student_display_name`, score, percentage, completion_time, plus `participant_id`/`qualified` for teacher/admin views); `teacher.recalculateLeaderboard`; `teacher.disqualifyParticipant`.

**Student reset-password:** `teacher.resetStudentPassword(id, { password, password_confirmation })` — `ResetPasswordRequest` requires `password.confirmed`. All reset modals (Teacher/Students, Teacher/Assistants, Admin/Students, Admin/Teachers) send both fields + a confirmation input.

---

## 8. Security & Content-Protection Compliance

- **Video protection:** students watch inside Teacher-System (no YouTube watch/channel/playlist UI). Client never persists the provider id; it stays only in volatile runtime memory and is cleared on destroy (see `Video_Content_Protection_Report.md`). Watermark = display name + opaque session code (never passwords/tokens). No fake DRM/DevTools-disable claims.
- **No sensitive collection:** no webcam/mic/screen/keylogging/clipboard/GPS/browser-history collection. Security logging covers failures/visibility/fullscreen-exits/repeated-DevTools only.
- **Student identity:** teacher creates name/email/password; student self-completes profile; email not student-editable; password self-change requires `current_password`; manager reset revokes tokens; deactivation revokes tokens.
- **Server-authoritative:** client can never set score/rank/completion-time/qualification/integrity conclusions; exams are server-authoritative with display-only timers.
- **No IDOR:** provider-id is not manipulable; `VideoResource` is staff-only; normal student APIs never expose provider metadata.
- **`qualified`** = competition ranking eligibility (not exam pass/fail); leaderboard uses privacy-safe `publicDisplayName()`.

---

## 9. Known Limitations / Notes

1. **Runtime tests not executed** — PHP is unavailable in this sandbox. The frontend was verified via production build + static contract checks. Do not claim runtime tests passed.
2. **No live preview served** — the SPA was built (`dist/`) but not tested against a running PHP host.
3. **No marketplace/billing, no fake data** — all views consume real backend contracts; no mock/placeholder production data was left in place.
4. **`apicompat.js` parser corrected** — see §2 note; this is a tooling fix, not a frontend change.
5. **`CoursePolicy::manageEnrollments` duplicate removed** — a duplicate method that would have caused a PHP fatal (`Cannot redeclare`) was removed; the surviving method (admin ⏐ assistant ⏐ owned) is unchanged.

---

## 10. Conclusion

All agreed acceptance criteria are met at the source/contract level: real backend endpoints, correct role/permission boundaries (including the new minimal `assistant` role), correct response-shape handling, exam/competition/video-integrity flows, and no invented APIs or fake data. The SPA builds and every frontend API call resolves to a real backend route.

**Status: `READY FOR TESTING`** — pending runtime/feature testing against a PHP host.
