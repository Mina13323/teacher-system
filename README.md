# AI Marketing & Learning Platform — Laravel LMS

A Laravel 11 API for an AI Marketing & Learning Platform (LMS).

- **Phase 1 — Foundation:** authentication (Sanctum), roles/permissions
  (Spatie), API versioning, and the base database/API scaffolding.
- **Phase 2 — LMS Core:** full teacher content management (courses → units →
  lessons → videos), student enrollment, lesson progress, course/unit progress,
  interactive roadmap, and teacher/student dashboard foundations.
- **Phase 3 — Examination System:** teacher exam/question/option management and
  publish validation; student exam discovery, attempt start, answering,
  server-side grading, and submission. Attempts use a **frozen snapshot** so they
  are immutable historical representations of the exam as it was at start.
- **Phase 3.1 — Examination Hardening:** snapshot deletion integrity (teacher
  question/option edits/deletions never corrupt existing attempts), frozen
  pass-percentage per attempt, single-active-attempt DB constraint, and
  regression tests.
- **Phase 4 — Anti-Cheat & Exam Integrity:** per-exam integrity configuration,
  student integrity-event recording, deterministic risk scoring, attempt
  integrity status, teacher review with an immutable audit trail, and dedicated
  rate limiting. Privacy-conscious — it records events and evidence, never
  arbitrary personal data.

Webcam/microphone/screen/keylogging/browser-fingerprinting/GPS monitoring, AI
cheating detection, competitions, leaderboards, subscriptions, payments,
advanced analytics, AI features and video streaming/transcoding are
intentionally **not** implemented yet.

---

## Requirements

- PHP >= 8.2 (extensions: `pdo`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `sqlite3` or `pdo_mysql`)
- Composer
- SQLite (default) or MySQL/Postgres

## Install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_CONNECTION` in `.env` (default is SQLite).

## Run migrations & seeders

```bash
php artisan migrate:fresh --seed
```

The seeders create the `admin`, `teacher`, `student` roles, the full permission
catalogue, and three **development-only** accounts:

| Role    | Email                 | Password   |
|---------|-----------------------|------------|
| Admin   | `admin@example.com`   | `password` |
| Teacher | `teacher@example.com` | `password` |
| Student | `student@example.com` | `password` |

> **Do not use these credentials in production.**

## Run tests

```bash
php artisan test
```

## Start the development server

```bash
php artisan serve
```

The API is mounted under `/api/v1`.

## Architecture

```
app/
├── Actions/           business operations (auth, course/unit/lesson/video
│                      CRUD, enrollment, progress, roadmap)
├── Console/
├── Enums/             UserRole, CourseStatus, EnrollmentStatus,
│                      RoadmapStatus, RoadmapLessonStatus
├── Events/
├── Exceptions/        API-specific exceptions
├── Http/
│   ├── Controllers/   Auth/, Teacher/, Student/, public CourseController
│   ├── Middleware/    ForceJsonResponse
│   ├── Requests/      Form Requests (validation)
│   └── Resources/     API Resources
├── Models/            Course, Unit, Lesson, Video, Enrollment, LessonProgress
├── Policies/          server-side authorization
├── Providers/
├── Repositories/
├── Services/
├── Notifications/
└── Support/           ApiResponse trait
```

Controllers are thin; business logic lives in `Actions`, validation in Form
Requests, responses in Resources, and authorization in Policies.

## Authentication

Token-based API authentication via **Laravel Sanctum**. Passwords are hashed
and never returned in responses.

## Authorization

Roles & permissions via **Spatie Laravel Permission** (`admin` / `teacher` /
`student`). Admins are granted all abilities through a `Gate::before` hook.
Policies enforce ownership on the server:

- A teacher manages only their own courses (and their units/lessons/videos).
- A student cannot create, update or delete courses.
- A student accesses only the courses they are enrolled in.
- A student records progress only for their own active enrollments.

## API Documentation

See [`docs/API.md`](docs/API.md) for the complete Phase 2 endpoint reference
(method, URL, authentication, role/permission, request body, validation,
response and possible errors).

## Phase 1 scope (summary)

- `/api/v1` versioning, JSON response envelope, centralized exception handling.
- Sanctum register/login/logout/me.
- Roles & permissions, Policies, Form Requests, API Resources.
- Base migrations for courses, units, lessons, videos, enrollments,
  lesson_progress, roles/permissions, personal access tokens.

## Phase 2 scope (summary)

- Teacher course CRUD + publish/unpublish.
- Teacher unit / lesson / video CRUD + reorder + publish/unpublish.
- Public course discovery (published only).
- Student enrollment (duplicate-safe) and enrolled-course access.
- Lesson progress tracking with 0–100 rules and automatic completion.
- Course & unit progress calculation (computed, not stored).
- Interactive course roadmap (per-lesson states).
- Teacher & student dashboard foundations.
