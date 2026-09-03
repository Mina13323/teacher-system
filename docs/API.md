# API Reference — v1

Base URL: `/api/v1`

All responses use a consistent envelope:

```json
{ "success": true, "message": "...", "data": { } }
```

Errors:

```json
{ "success": false, "message": "...", "errors": { } }
```

## Authentication

| Method | URL                        | Auth            | Role/Permission | Request body | Response          |
|--------|----------------------------|-----------------|-----------------|--------------|-------------------|
| POST   | `/auth/register`           | —               | —               | `name, email, password, password_confirmation` | `{ user, token }` |
| POST   | `/auth/login`              | —               | —               | `email, password` | `{ user, token }` |
| GET    | `/auth/me`                 | Bearer token    | authenticated   | —            | `{ user }`        |
| POST   | `/auth/logout`             | Bearer token    | authenticated   | —            | —                 |

---

## Public course discovery

Only **published** courses are returned to unauthenticated clients. Unpublished
lessons/videos are never exposed here.

| Method | URL                   | Auth | Role/Permission | Request body | Response        |
|--------|-----------------------|------|-----------------|--------------|-----------------|
| GET    | `/courses`            | —    | —               | —            | `CourseResource[]` |
| GET    | `/courses/{course}`   | —    | —               | —            | `CourseDetailResource` |

---

## Teacher / Admin — Courses

| Method | URL                                   | Auth | Role/Permission | Request body | Response |
|--------|---------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/dashboard`                  | Bearer | `teacher`/`admin` | —          | teacher dashboard |
| GET    | `/teacher/courses`                    | Bearer | `teacher`/`admin` | —          | `CourseResource[]` |
| POST   | `/teacher/courses`                    | Bearer | `courses.create` | `title, slug?, description?, thumbnail?, status?` | `CourseResource` |
| GET    | `/teacher/courses/{course}`           | Bearer | owns course / admin | —          | `CourseDetailResource` |
| PUT    | `/teacher/courses/{course}`           | Bearer | owns course + `courses.update` | `title?, slug?, description?, thumbnail?, status?` | `CourseResource` |
| PATCH  | `/teacher/courses/{course}/publish`   | Bearer | owns course + `courses.update` | —          | `CourseResource` |
| PATCH  | `/teacher/courses/{course}/unpublish` | Bearer | owns course + `courses.update` | —          | `CourseResource` |
| DELETE | `/teacher/courses/{course}`           | Bearer | owns course + `courses.delete` | —          | — |

**Errors:** 401 unauthenticated, 403 not owner, 404 not found.

---

## Teacher / Admin — Units

| Method | URL                                          | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/courses/{course}/units`            | Bearer | owns course / admin | —          | `UnitResource[]` |
| POST   | `/teacher/courses/{course}/units`            | Bearer | owns course + `courses.update` | `title, description?, position?` | `UnitResource` |
| PUT    | `/teacher/courses/{course}/units/reorder`    | Bearer | owns course + `courses.update` | `ordered_ids[]` | — |
| GET    | `/teacher/units/{unit}`                      | Bearer | owns course / admin | —          | `UnitDetailResource` |
| PUT    | `/teacher/units/{unit}`                      | Bearer | owns course + `courses.update` | `title?, description?, position?` | `UnitResource` |
| DELETE | `/teacher/units/{unit}`                      | Bearer | owns course + `courses.delete` | —          | — |

**Validation:** `title` required (max 255); `position` integer ≥ 0.

---

## Teacher / Admin — Lessons

| Method | URL                                          | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/units/{unit}/lessons`              | Bearer | owns course / admin | —          | `LessonResource[]` |
| POST   | `/teacher/units/{unit}/lessons`              | Bearer | owns course + `lessons.update` | `title, slug?, description?, content?, position?, is_published?` | `LessonResource` |
| PUT    | `/teacher/units/{unit}/lessons/reorder`      | Bearer | owns course + `lessons.update` | `ordered_ids[]` | — |
| GET    | `/teacher/lessons/{lesson}`                  | Bearer | owns course / admin | —          | `LessonDetailResource` |
| PUT    | `/teacher/lessons/{lesson}`                  | Bearer | owns course + `lessons.update` | `title?, slug?, description?, content?, position?, is_published?` | `LessonDetailResource` |
| PATCH  | `/teacher/lessons/{lesson}/publish`          | Bearer | owns course + `lessons.update` | —          | `LessonResource` |
| PATCH  | `/teacher/lessons/{lesson}/unpublish`        | Bearer | owns course + `lessons.update` | —          | `LessonResource` |
| DELETE | `/teacher/lessons/{lesson}`                  | Bearer | owns course + `lessons.delete` | —          | — |

---

## Teacher / Admin — Videos

Videos are metadata only (no transcoding / streaming).

| Method | URL                                          | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------------|------|-----------------|--------------|----------|
| GET    | `/teacher/lessons/{lesson}/videos`           | Bearer | owns course / admin | —          | `VideoResource[]` |
| POST   | `/teacher/lessons/{lesson}/videos`           | Bearer | owns course + `lessons.update` | `title, storage_path?, duration?, position?, is_published?` | `VideoResource` |
| PUT    | `/teacher/lessons/{lesson}/videos/reorder`   | Bearer | owns course + `lessons.update` | `ordered_ids[]` | — |
| GET    | `/teacher/videos/{video}`                    | Bearer | owns course / admin | —          | `VideoResource` |
| PUT    | `/teacher/videos/{video}`                    | Bearer | owns course + `lessons.update` | `title?, storage_path?, duration?, position?, is_published?` | `VideoResource` |
| PATCH  | `/teacher/videos/{video}/publish`            | Bearer | owns course + `lessons.update` | —          | `VideoResource` |
| PATCH  | `/teacher/videos/{video}/unpublish`          | Bearer | owns course + `lessons.update` | —          | `VideoResource` |
| DELETE | `/teacher/videos/{video}`                    | Bearer | owns course + `lessons.delete` | —          | — |

---

## Student — Enrollment & access

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| POST   | `/student/courses/{course}/enroll`     | Bearer | `student` / `students.manage` | —          | `EnrollmentResource` |
| GET    | `/student/courses`                     | Bearer | `student` | —          | `StudentCourseResource[]` |
| GET    | `/student/courses/{course}`            | Bearer | enrolled + `student` | —          | `StudentCourseResource` (with roadmap) |
| GET    | `/student/courses/{course}/roadmap`    | Bearer | enrolled + `student` | —          | `CourseRoadmapResource` |

**Errors:** 401 unauthenticated, 409 duplicate enrollment, 422 course not
published, 404 not enrolled / not found.

---

## Student — Progress

| Method | URL                                    | Auth | Role/Permission | Request body | Response |
|--------|----------------------------------------|------|-----------------|--------------|----------|
| GET    | `/student/progress`                    | Bearer | `student` | —          | `LessonProgressResource[]` |
| GET    | `/student/lessons/{lesson}/progress`   | Bearer | enrolled + `student` | —          | `LessonProgressResource` |
| PUT    | `/student/lessons/{lesson}/progress`   | Bearer | enrolled + `student` | `progress_percentage, last_position_seconds?, completed?` | `LessonProgressResource` |

**Validation:** `progress_percentage` required integer 0–100;
`last_position_seconds` integer ≥ 0; `completed` boolean.

**Behavior:** `completed` becomes true (and `completed_at` set) automatically
at 100%; otherwise `completed` is false and `completed_at` null.

---

## Student — Dashboard

| Method | URL                | Auth | Role | Response |
|--------|--------------------|------|------|----------|
| GET    | `/student/dashboard` | Bearer | `student` | `{ enrolled_courses_count, completed_lessons_count, in_progress_lessons_count, recently_accessed_lessons, courses }` |

---

## Status codes

| Code | Meaning |
|------|---------|
| 200  | Success |
| 201  | Created |
| 401  | Unauthenticated |
| 403  | Unauthorized / inaccessible |
| 404  | Not found |
| 409  | Duplicate enrollment |
| 422  | Validation failed / course not available |
