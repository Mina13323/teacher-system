# AI Marketing & Learning Platform — Phase 1 Foundation

A Laravel 11 foundation for an AI Marketing & Learning Platform (LMS)
implementing authentication, role/permission management, and the initial
database + API scaffolding for courses, units, lessons, videos, enrollments
and lesson progress.

This is **Phase 1 only**. Exams, anti-cheat, competitions, leaderboards,
subscriptions, analytics, AI features and video streaming are intentionally
**not** implemented yet — the architecture is prepared so they can be added
later without major restructuring.

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

Set `DB_CONNECTION` in `.env` (default is SQLite). For SQLite the database file is
created automatically when you run the migrations.

## Run migrations & seeders

```bash
php artisan migrate:fresh --seed
```

The seeders create the `admin`, `teacher`, `student` roles, the full permission
catalogue, and three **development-only** accounts:

| Role    | Email               | Password |
|---------|---------------------|----------|
| Admin   | `admin@example.com` | `password` |
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
├── Actions/          → business operations (auth, course CRUD)
├── Console/
├── Enums/            → UserRole, CourseStatus, EnrollmentStatus
├── Events/
├── Exceptions/       → API-specific exceptions
├── Http/
│   ├── Controllers/
│   │   ├── Auth/     → AuthController
│   │   └── Teacher/  → CourseController
│   ├── Middleware/   → ForceJsonResponse
│   ├── Requests/     → Form Requests (validation)
│   └── Resources/    → API Resources
├── Models/           → Course, Unit, Lesson, Video, Enrollment, LessonProgress
├── Policies/         → server-side authorization
├── Providers/
├── Repositories/
├── Services/
├── Notifications/
└── Support/          → ApiResponse trait
```

Controllers stay thin; business logic lives in `Actions`, validation in Form
Requests, responses in Resources, and authorization in Policies.

## Authentication

Token-based API authentication via **Laravel Sanctum**. Passwords are hashed
and never returned in responses.

## Authorization

Roles & permissions via **Spatie Laravel Permission** (`admin` / `teacher` /
`student`). Admins are granted all abilities through a `Gate::before` hook.
Policies enforce ownership on the server for courses/units/lessons/videos and
self-access for enrollments/progress.
