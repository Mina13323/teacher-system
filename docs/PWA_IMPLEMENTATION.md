# Teacher-System — Progressive Web App (PWA) Implementation & Security Audit

## 1. Overview & Architecture

The Teacher-System Vue 3 + Vite Single Page Application has been upgraded to a production-ready Progressive Web App (PWA) using `vite-plugin-pwa` and Workbox. 

Users can install Teacher-System on:
- Android (Chrome, Edge, Firefox, Brave)
- iOS / iPadOS (Safari via Share → Add to Home Screen)
- Windows (Chrome, Edge)
- macOS (Chrome, Edge, Safari)

The PWA runs as a standalone window with native app icons, theme colors, safe-area viewport handling, offline network indicators, and update notifications.

---

## 2. Web App Manifest Specifications

- **Manifest File:** Generated via `vite-plugin-pwa` at `/manifest.webmanifest`
- **Application Name:** `Atlas Academy — Geography & History`
- **Short Name:** `Atlas Academy`
- **Start URL:** `/`
- **Scope:** `/`
- **Display Mode:** `standalone`
- **Theme Color:** `#c84b1a` (Terracotta primary accent)
- **Background Color:** `#fdfbf7` (Parchment background)
- **Orientation:** `any` (responsive across portrait and landscape)
- **Icons (`public/`):**
  - `pwa-192x192.png` (192x192 PNG, purpose: `any`)
  - `pwa-512x512.png` (512x512 PNG, purpose: `any`)
  - `maskable-icon-512x512.png` (512x512 PNG, purpose: `maskable` with safe padding)
  - `apple-touch-icon.png` (180x180 PNG)
  - `favicon.svg` (Vector SVG compass icon)

---

## 3. Service Worker & Safe Caching Strategy

The service worker is configured via Workbox with a **strict security-first caching policy**.

### ⚠️ Exclusions & NetworkOnly Rules:
- **API Requests (`/api/v1/*`):** Explicitly matched by `/^\/api\/.*/` and routed to `NetworkOnly`.
- **Sanctum Authentication:** Authentication requests, CSRF cookies, and user session payloads are NEVER cached.
- **Protected Video Tokens & Playback:** Video playback tokens (`/api/v1/student/videos/{video}/playback`) and media sessions remain online-only.
- **Exams & Server-Side Grading:** Exam questions, attempts, answers, submission payloads, and integrity logs remain server-authoritative and online-only.
- **Competitions & Leaderboards:** Leaderboards and competition participant data are fetched fresh from the server.
- **Notifications & Personal Info:** Private student data and in-app notifications are never stored in service worker caches.

### ✅ Static Asset Caching Rules:
- **JS Chunks & CSS:** Cached via Workbox glob patterns (`**/*.{js,css,html,ico,png,svg,woff2}`).
- **Public Fonts:** Google Fonts (`fonts.googleapis.com` & `fonts.gstatic.com`) are cached via `CacheFirst` (max 10 entries, 1 year expiry).
- **Public Landing Page Assets:** Public graphics and icons are cached safely for offline landing page rendering.

---

## 4. Offline & Installation Experience

### Offline Degradation ([OfflineBanner.vue](file:///C:/Users/Mina%20Wael/Desktop/teacherAssistant/resources/js/components/pwa/OfflineBanner.vue))
- When connection is lost, a non-intrusive warning banner appears: *"You are currently offline. Active features (exams, video playback, live actions) require internet connectivity."*
- Includes a **Retry** button.
- When connection returns, displays a brief *"Back online"* green confirmation indicator.
- Does **NOT** simulate fake offline exam submissions or bypass server validation.

### Install Prompt ([AppInstallPrompt.vue](file:///C:/Users/Mina%20Wael/Desktop/teacherAssistant/resources/js/components/pwa/AppInstallPrompt.vue))
- Listens to `beforeinstallprompt` event and detects if the app is already running in `standalone` display mode.
- Renders a floating, elegant install banner with an **Install App** CTA.
- Provides a **Dismiss** option that remembers user preference per session.
- On iOS devices, displays custom Safari share instructions: *"To install on iPhone/iPad: Tap Share → Add to Home Screen"*.

### Update Notifications ([PwaUpdateToast.vue](file:///C:/Users/Mina%20Wael/Desktop/teacherAssistant/resources/js/components/pwa/PwaUpdateToast.vue))
- Uses `registerType: 'prompt'`.
- When a new frontend version is built and deployed, a toast appears: *"New version available — Update App"*.
- Clicking **Update App** safely triggers `updateServiceWorker(true)` to refresh assets without breaking active user sessions.

---

## 5. Security & Isolation Audit

| Security Criterion | Audit Verification | Status |
| :--- | :--- | :---: |
| **No Private API Responses Cached** | Workbox handler for `/^\/api\/.*/` is explicitly `NetworkOnly` | **VERIFIED** |
| **No Authentication Secrets Cached** | Sanctum tokens, passwords, and user profiles bypass SW cache | **VERIFIED** |
| **No Protected Video Bypasses** | Video playback tokens and session endpoints are online-only | **VERIFIED** |
| **No Offline Exam Grading** | Exam submission and timer remain server-authoritative | **VERIFIED** |
| **No Client-Side Secrets** | Zero secrets or private keys stored in manifest or SW | **VERIFIED** |
| **Route Guard Integrity** | Vue Router `beforeEach` role guards enforce Sanctum auth | **VERIFIED** |

---

## 6. Hostinger Production Deployment

- **Architecture:** Compiled Vue SPA (`dist/`) served alongside Laravel backend via single-origin Apache `.htaccess`.
- **No Node Process Required:** `npm run build` generates all static distribution files and service worker bundles during deployment.
- **Service Worker Scope:** Root `/` scope compatible with Hostinger directory structures.
