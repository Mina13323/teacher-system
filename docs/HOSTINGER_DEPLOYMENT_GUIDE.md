# Teacher-System: Hostinger Production Deployment Guide

> [!IMPORTANT]
> **DEPLOYMENT STATUS: DEPLOYMENT READY**
> This guide outlines the exact, step-by-step procedure for deploying Teacher-System (Laravel 11 REST API + Vue 3 / Vite PWA Single Page Application) to Hostinger Shared or Cloud Hosting under a single domain.

---

## 1. Architecture

The production environment operates under a single-origin architecture:

```text
https://your-domain.com/              --> Serves Vue 3 PWA (index.html + JS/CSS assets)
https://your-domain.com/api/v1/*       --> Executes Laravel 11 REST API (Sanctum Auth)
https://your-domain.com/storage/*      --> Serves public uploads / static assets
```

- **Backend:** Laravel 11 running on PHP 8.2+ / MySQL.
- **Frontend:** Vue 3 SPA compiled with Vite, enhanced with Service Worker PWA (`vite-plugin-pwa` + Workbox).
- **Web Server:** Apache with `mod_rewrite` handling static asset resolution, API routing to `index.php`, and SPA route fallback to `index.html`.
- **Node.js Requirement:** Zero permanently running Node.js / Vite server process required on the production host.

---

## 2. Prerequisites

1. Hostinger Shared / Cloud hosting account with PHP 8.2 or 8.3 enabled.
2. MySQL Database created via Hostinger hPanel.
3. SSL Certificate enabled for the target domain (`https://your-domain.com`).
4. SSH or FTP access to the Hostinger account.
5. Local machine with PHP 8.2+, Composer, Node.js 20+, and Git.

---

## 3. Hostinger Configuration

In Hostinger hPanel:
1. **PHP Version:** Set PHP version to **8.2** or **8.3**. Enable PHP extensions: `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.
2. **Domain Document Root:** Point the domain's document root to Laravel's `public` directory (e.g., `public_html/public` or move `public` contents to `public_html` as detailed below).

---

## 4. Database Setup

1. Log in to Hostinger hPanel $\rightarrow$ **Databases** $\rightarrow$ **MySQL Databases**.
2. Create a new database and database user:
   - **Database Name:** `u123456789_teacher`
   - **Database User:** `u123456789_user`
   - **Database Password:** `<STRONG_SECURE_PASSWORD>`
3. Note down the host (usually `localhost` or `127.0.0.1` on Hostinger), database name, user, and password.

---

## 5. Environment Variables (.env)

Create a new `.env` file on the production server (never commit `.env` to Git):

```ini
APP_NAME="Atlas Academy"
APP_ENV=production
APP_KEY=base64:GENERATE_ON_SERVER_WITH_ARTISAN
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://your-domain.com

LOG_CHANNEL=daily
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=u123456789_teacher
DB_USERNAME=u123456789_user
DB_PASSWORD=<STRONG_SECURE_PASSWORD>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=your-domain.com

SANCTUM_STATEFUL_DOMAINS=your-domain.com

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CACHE_STORE=file
```

> [!CAUTION]
> Ensure `APP_DEBUG=false` in production to prevent leaking tracebacks or environment secrets.

---

## 6. Laravel Backend Deployment

1. **Upload Files:** Upload the repository files (excluding `node_modules/`, `vendor/`, `.git/`, `.env`) to the server directory (e.g., `public_html`).
2. **Install PHP Dependencies:** Run via SSH:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
3. **Generate Application Key:**
   ```bash
   php artisan key:generate --force
   ```

---

## 7. Vue PWA Frontend Deployment

1. **Build Locally:** Run locally on your development machine:
   ```bash
   npm run build
   ```
   This generates compiled assets in `dist/` (`index.html`, `assets/`, `sw.js`, `manifest.webmanifest`, icons).
2. **Upload Static Artifacts:**
   Copy all files inside `dist/` directly into Laravel's `public/` folder on Hostinger:
   - `dist/index.html` $\rightarrow$ `public/index.html`
   - `dist/assets/*` $\rightarrow$ `public/assets/*`
   - `dist/sw.js` $\rightarrow$ `public/sw.js`
   - `dist/manifest.webmanifest` $\rightarrow$ `public/manifest.webmanifest`
   - `dist/pwa-*.png` $\rightarrow$ `public/pwa-*.png`
   - `dist/apple-touch-icon.png` $\rightarrow$ `public/apple-touch-icon.png`

---

## 8. Apache Configuration (.htaccess)

Ensure `public/.htaccess` contains:

```apache
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Force HTTPS
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

    # Handle Authorization Header for Sanctum Bearer tokens
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send API Requests & Existing Files To Front Controller
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Security: Block direct access to hidden files (.env, .git)
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>
```

---

## 9. Storage Setup

1. **Create Public Symlink:**
   ```bash
   php artisan storage:link
   ```
2. **Directory Isolation:** Ensure `storage/app/private` and `storage/logs` are inaccessible from the web.

---

## 10. File Permissions

Set strict production file permissions via SSH or Hostinger File Manager:

```bash
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
```

---

## 11. HTTPS Configuration

- SSL must be active for the domain in hPanel.
- Verify `APP_URL` in `.env` starts with `https://`.
- Service Worker registration requires HTTPS (except on `localhost`).

---

## 12. Production Artisan Commands

Execute the following optimization commands on Hostinger SSH:

```bash
# 1. Run database migrations safely (NEVER use migrate:fresh in production)
php artisan migrate --force

# 2. Seed initial core roles & permissions
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=PermissionSeeder --force

# 3. Cache configuration and routes
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> [!WARNING]
> DO NOT run `php artisan db:seed --class=DemoContentSeeder` in production. Demo seeders are strictly for development/demo testing.

---

## 13. First Admin / Teacher Setup

To create the initial Teacher account safely on production without demo seeders, run via SSH interactive CLI:

```bash
php artisan tinker
```

Then execute inside Tinker:

```php
$teacher = \App\Models\User::create([
    'name' => 'Main Teacher',
    'email' => 'teacher@your-domain.com',
    'password' => \Illuminate\Support\Facades\Hash::make('SetAStrongPasswordHere123!'),
    'is_active' => true,
    'email_verified_at' => now(),
]);
$teacher->assignRole(\App\Enums\UserRole::Teacher);
```

---

## 14. PWA Verification

1. Open `https://your-domain.com/` in Chrome.
2. Open DevTools $\rightarrow$ **Application** $\rightarrow$ **Manifest**:
   - Verify Name: `Atlas Academy — Geography & History`
   - Verify Start URL: `/`
   - Verify Display: `standalone`
3. Check **Service Workers**:
   - Verify `/sw.js` status is **Activated and Running**.
4. Check **Cache Storage**:
   - Confirm static JS/CSS assets are cached.
   - Confirm **`/api/v1/*` requests are NOT cached** (NetworkOnly).

---

## 15. Authentication Verification

1. Navigate to `https://your-domain.com/login`.
2. Login with teacher credentials.
3. Confirm Bearer token is stored in `localStorage` under `atlas.auth.token`.
4. Confirm response header returns `200 OK` and redirects to `/teacher`.

---

## 16. API Verification

1. Execute unauthenticated request:
   ```bash
   curl -i https://your-domain.com/api/v1/courses
   ```
   **Expected:** `401 Unauthorized`.
2. Execute authenticated request with Bearer token:
   ```bash
   curl -i -H "Authorization: Bearer <TOKEN>" https://your-domain.com/api/v1/courses
   ```
   **Expected:** `200 OK` with JSON envelope `{ "success": true, "data": [...] }`.

---

## 17. Protected Course Verification

1. Visit `https://your-domain.com/courses` while logged out.
2. **Expected:** Browser immediately redirects to `https://your-domain.com/login?redirect=/courses`.

---

## 18. Protected Video Verification

1. Log in as a student enrolled in a course.
2. Open a video lesson.
3. Verify video stream request calls `GET /api/v1/student/videos/{video}/playback` with Bearer token.
4. Verify response contains short-lived playback session payload without leaking raw storage paths or provider credentials.

---

## 19. Exam Verification

1. Start a timed exam attempt as an enrolled student.
2. Verify timer counts down accurately.
3. Submit answers and confirm instant server-side grading.
4. Verify answer key is never exposed in client API responses.

---

## 20. Logout Verification

1. Click **Sign Out** in the user menu.
2. Verify `POST /api/v1/auth/logout` succeeds.
3. Verify `localStorage` token is cleared.
4. Click browser **Back** button: confirm page revalidates auth and redirects to `/login`.

---

## 21. Production Smoke Test

- [x] Landing page (`/`) loads without login.
- [x] Login (`/login`) accepts valid credentials.
- [x] Direct navigation to `/courses` redirects unauthenticated users to `/login`.
- [x] `/api/v1/courses` returns `401 Unauthorized` unauthenticated.
- [x] PWA install banner displays on supported browsers.
- [x] Service worker precaches static assets and uses `NetworkOnly` for `/api/*`.
- [x] No `127.0.0.1`, `localhost`, or port `5173`/`8000` URLs present in bundle.

---

## 22. Rollback Procedure

If a critical issue occurs during deployment:
1. Re-upload previous static `dist/` bundle to `public/`.
2. Revert `.env` modifications if any.
3. Clear Laravel caches:
   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```
4. If database migration needs rollback:
   ```bash
   php artisan migrate:rollback --step=1 --force
   ```
