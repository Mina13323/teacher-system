# Teacher-System: Hostinger Deployment Runbook (Not Deployment Clearance)

> [!CAUTION]
> **NOT CLEARED FOR PRODUCTION DEPLOYMENT.** This is an operational runbook, not release approval. The current audit has an unsupported/advisory-affected Laravel 11 dependency, failing observed PHP CI, and untested backend, migration, backup/restore, browser, and staging controls. See [`PRODUCTION_READINESS_AUDIT.md`](PRODUCTION_READINESS_AUDIT.md) and do not deploy until its release gates are satisfied.
>
> The code currently uses Laravel 11; its security-support period ended on 2026-03-12. Upgrade to a currently supported, patched framework version and validate the PHP runtime before following this runbook.

---

## 1. Architecture

The production environment operates under a single-origin architecture:

```text
https://your-domain.com/              --> Serves Vue 3 PWA (index.html + JS/CSS assets)
https://your-domain.com/api/v1/*       --> Executes Laravel REST API (current lock: Laravel 11; not production-approved)
https://your-domain.com/storage/*      --> Serves public uploads / static assets
```

- **Backend:** Laravel API on PHP / MySQL; the current dependency lock is Laravel 11 and must be upgraded to a supported, patched line before production.
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

### Backup and restore release gate

The current `db:backup` command stores local-only files. SQLite uses standalone `VACUUM INTO` artifacts; MySQL/MariaDB uses SQL dumps. No encryption/off-host destination is implemented by this command, and no MySQL restore or production recovery drill has been executed. A local artifact alone is not a proven disaster-recovery plan. Before production, configure encrypted off-host retention outside this command, verify permissions and monitoring, and restore representative data into a disposable target against the intended database engine. These checks remain **NOT TESTED**; do not mark this deployment guide as completed until the current audit's recovery gates are cleared.

---

## 5. Environment Variables (.env)

Create a new `.env` file on the production server (never commit `.env` to Git):

```ini
APP_NAME="El Masry"
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

1. **Build locally:** Run on your development machine:
   ```bash
   npm run build
   ```
   The build creates `dist/` and publishes the generated SPA/PWA files into the tracked `public/` paths. It also verifies the entrypoint references, manifest, worker, and required icon files.
2. **Deploy the generated output with the application:**
   Deploy the refreshed `public/index.html`, `public/assets/`, `public/sw.js`, and `public/manifest.webmanifest` together. Keep the hand-maintained icons and `public/push-sw.js` in place; the service worker imports its Web Push handlers from that file.

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

    # Never rewrite missing static assets to index.php (avoids HTML/MIME errors)
    RewriteCond %{REQUEST_URI} \.(?:css|js|map|jpe?g|gif|png|webp|svg|woff2?|ttf|eot|ico)$ [NC,OR]
    RewriteCond %{REQUEST_URI} ^/assets/ [NC]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ - [R=404,L]

    # Send Requests To Front Controller
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
    # Keep the app shell, manifest, and service-worker scripts revalidatable.
    <FilesMatch "^(index\.html|sw\.js|push-sw\.js|manifest\.webmanifest)$">
        Header set Cache-Control "no-cache, no-store, must-revalidate"
        Header set Pragma "no-cache"
        Header set Expires "0"
    </FilesMatch>

    # Static root files (including PWA icons) can refresh daily; workers cannot.
    <FilesMatch "^(?!sw\.js$|push-sw\.js$).*\.(css|js|woff2?|png|svg|ico)$">
        Header set Cache-Control "public, max-age=86400"
    </FilesMatch>

    # Only Vite's content-hashed filenames are immutable for a year.
    <FilesMatch "^[A-Za-z0-9_-]+-[A-Za-z0-9_-]{8,}\.(css|js|woff2?|png|svg|ico)$">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </FilesMatch>
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
   - Verify Name: `El Masry — Geography & History`
   - Verify Start URL: `/`
   - Verify Display: `standalone`
3. Check **Service Workers**:
   - Verify `/sw.js` status is **Activated and Running**.
   - When a new version is deployed, wait for the update banner and select **Update App**; it stays user-confirmed so an active exam is not interrupted.
4. Check response headers in the Network panel:
   - `index.html`, `manifest.webmanifest`, `sw.js`, and `push-sw.js` must revalidate / use `no-store`.
   - Content-hashed `/assets/*` may use a one-year immutable cache.
   - Purge the Hostinger/LiteSpeed/CDN cache once after deploying these header changes.
5. Check **Cache Storage**:
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

## 21. Release smoke-test checklist (not executed in the current audit)

Run these checks only in an approved staging/deployment environment after all release gates pass.

- [ ] Landing page (`/`) loads without login.
- [ ] Login (`/login`) accepts valid credentials.
- [ ] Direct navigation to `/courses` redirects unauthenticated users to `/login`.
- [ ] `/api/v1/courses` returns `401 Unauthorized` unauthenticated.
- [ ] PWA install banner displays on supported browsers.
- [ ] Service worker precaches static assets and uses `NetworkOnly` for `/api/*`.
- [ ] No `127.0.0.1`, `localhost`, or port `5173`/`8000` URLs present in bundle.
- [ ] Current audit and CI release gates are cleared before production deployment.

---

## 22. Rollback Procedure

If a critical issue occurs during deployment:
1. Re-upload a known-good static asset bundle only after confirming it is compatible with the deployed API/schema.
2. Revert `.env` modifications only after reviewing their impact.
3. Clear Laravel caches:
   ```bash
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   ```
4. **Do not run a generic `migrate:rollback --step=1` as an automatic production rollback.** The current student-attempt FK migration's `down()` restores `ON DELETE CASCADE`; that can re-enable history loss. Any schema rollback needs an approved, migration-specific recovery plan, verified backup, and tested procedure. Migration execution/rollback is **NOT TESTED** in the current audit.
