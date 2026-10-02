#!/usr/bin/env node
/**
 * Build the SPA/PWA and publish the result into Laravel's `public/`.
 *
 * WHY THIS EXISTS
 * ---------------
 * Deployment is `git pull` on Hostinger with Laravel's `public/` served
 * directly, so the compiled frontend has to live in `public/` and stay tracked
 * in git. That constraint is intentional and is NOT changed here.
 *
 * The bug it fixes: Vite copies `public/` into `dist/` on every build. Because
 * the finished bundle is then published back into `public/`, each generation's
 * content-hashed chunks were re-copied into `public/assets` and never removed.
 * They accumulated across builds (up to 10 generations of the same logical
 * module), and `vite-plugin-pwa` precached all of them — every visitor's
 * service worker downloaded megabytes of dead JavaScript on first load.
 *
 * The fix: remove the generated paths from `public/` *before* building (so
 * Vite cannot pull stale chunks into `dist/`), build, then publish only the
 * generated paths back into `public/`.
 *
 * SAFETY
 * ------
 *  - Only paths this script itself generates are ever removed. Hand-maintained
 *    files in `public/` (index.php, .htaccess, robots.txt, icons) are never
 *    touched.
 *  - `public/` is only written after `vite build` exits 0. A failed build
 *    leaves `public/` alone rather than half-updated.
 *  - Nothing is committed or pushed by this script.
 */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pub = path.join(root, 'public');
const dist = path.join(root, 'dist');

/** Paths the build produces. Everything else in public/ is hand-maintained. */
const GENERATED_DIRS = ['assets'];
const GENERATED_FILES = ['index.html', 'sw.js', 'manifest.webmanifest'];
const WORKBOX_RE = /^workbox-[a-zA-Z0-9]+\.js$/;

const log = (m) => process.stdout.write(`${m}\n`);
const fail = (m) => {
    process.stderr.write(`\n[build-frontend] FAILED: ${m}\n`);
    process.exit(1);
};

function generatedEntries(dir) {
    if (!fs.existsSync(dir)) return [];
    return fs
        .readdirSync(dir)
        .filter((name) => GENERATED_DIRS.includes(name) || GENERATED_FILES.includes(name) || WORKBOX_RE.test(name));
}

function removeGenerated(dir, label) {
    const entries = generatedEntries(dir);
    for (const name of entries) {
        fs.rmSync(path.join(dir, name), { recursive: true, force: true });
    }
    log(`[build-frontend] cleared ${entries.length} generated ${label} entr${entries.length === 1 ? 'y' : 'ies'}`);
}

function copyRecursive(src, dest) {
    fs.mkdirSync(dest, { recursive: true });
    for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
        const s = path.join(src, entry.name);
        const d = path.join(dest, entry.name);
        if (entry.isDirectory()) copyRecursive(s, d);
        else fs.copyFileSync(s, d);
    }
}

function countFiles(dir) {
    if (!fs.existsSync(dir)) return 0;
    let n = 0;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        n += e.isDirectory() ? countFiles(path.join(dir, e.name)) : 1;
    }
    return n;
}

function dirBytes(dir) {
    if (!fs.existsSync(dir)) return 0;
    let b = 0;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        b += e.isDirectory() ? dirBytes(p) : fs.statSync(p).size;
    }
    return b;
}

// ---------------------------------------------------------------- 1. clean
// Remove previously generated output from public/ so Vite cannot copy stale
// chunks into dist/ and so they cannot be re-published.
removeGenerated(pub, 'public/');
fs.rmSync(dist, { recursive: true, force: true });

// ---------------------------------------------------------------- 2. build
log('[build-frontend] running vite build...');
// Use the current Node executable and local Vite entry point. Invoking `npx`
// on Windows can pick up an unrelated nvm shim, even when this build script
// itself is already running under a valid Node runtime.
const viteBin = path.join(root, 'node_modules', 'vite', 'bin', 'vite.js');
const build = spawnSync(process.execPath, [viteBin, 'build'], { cwd: root, stdio: 'inherit' });
if (build.status !== 0) {
    fail(`vite build exited with code ${build.status}. public/ was left untouched apart from generated output; re-run after fixing.`);
}
if (!fs.existsSync(path.join(dist, 'index.html'))) fail('vite build reported success but dist/index.html is missing.');

// ---------------------------------------------------------------- 3. publish
// Publish ONLY the generated paths. Hand-maintained public/ files are left
// exactly as they are.
for (const name of generatedEntries(dist)) {
    const src = path.join(dist, name);
    const dest = path.join(pub, name);
    if (fs.statSync(src).isDirectory()) copyRecursive(src, dest);
    else fs.copyFileSync(src, dest);
}

// ---------------------------------------------------------------- 4. verify
// The entrypoint must reference files that actually exist, otherwise a
// `git pull` would deploy a page that 404s on its own bundle.
const html = fs.readFileSync(path.join(pub, 'index.html'), 'utf8');
if (!html.includes('viewport-fit=cover')) fail('public/index.html must include a safe-area-aware mobile viewport.');
if (!html.includes('rel="manifest"') || !html.includes('/manifest.webmanifest')) {
    fail('public/index.html is missing the generated PWA manifest link.');
}
const refs = [...html.matchAll(/(?:src|href)="(\/assets\/[^"]+)"/g)].map((m) => m[1]);
if (!refs.length) fail('public/index.html references no /assets/ files — the build did not inject the bundle.');
const missing = refs.filter((r) => !fs.existsSync(path.join(pub, r)));
if (missing.length) fail(`public/index.html references ${missing.length} missing file(s): ${missing.join(', ')}`);

const swPath = path.join(pub, 'sw.js');
if (!fs.existsSync(swPath)) fail('public/sw.js was not generated — the PWA would lose its service worker.');
const sw = fs.readFileSync(swPath, 'utf8');
const precacheCount = (sw.match(/url:"\/?assets\//g) || []).length;
if (!sw.includes('push-sw.js')) fail('The generated service worker is missing the Web Push handler import.');

// Verify that the install metadata and all declared PWA icons are deployable.
const manifestPath = path.join(pub, 'manifest.webmanifest');
if (!fs.existsSync(manifestPath)) fail('public/manifest.webmanifest was not generated.');
let manifest;
try {
    manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
} catch {
    fail('public/manifest.webmanifest is not valid JSON.');
}
if (manifest.display !== 'standalone' || manifest.start_url !== '/' || manifest.scope !== '/') {
    fail('The PWA manifest must use standalone display and root start_url/scope.');
}
if (!Array.isArray(manifest.icons) || manifest.icons.length < 3) {
    fail('The PWA manifest must declare standard, high-resolution, and maskable icons.');
}
const declaredIconSizes = manifest.icons.map((icon) => String(icon.sizes || ''));
if (!declaredIconSizes.some((sizes) => sizes.includes('192x192'))
    || !declaredIconSizes.some((sizes) => sizes.includes('512x512'))
    || !manifest.icons.some((icon) => String(icon.purpose || '').split(/\s+/).includes('maskable'))) {
    fail('The PWA manifest must include 192px, 512px, and maskable icons.');
}
for (const icon of manifest.icons) {
    const iconPath = path.resolve(pub, String(icon.src || '').replace(/^\/+/, ''));
    if (!iconPath.startsWith(`${pub}${path.sep}`) || !fs.existsSync(iconPath) || fs.statSync(iconPath).size === 0) {
        fail(`The PWA manifest icon is missing or empty: ${icon.src || '(no src)'}`);
    }
}

const requiredPwaAssets = [
    'favicon.ico',
    'favicon.svg',
    'apple-touch-icon.png',
    'pwa-192x192.png',
    'pwa-512x512.png',
    'maskable-icon-512x512.png',
];
for (const name of requiredPwaAssets) {
    const assetPath = path.join(pub, name);
    if (!fs.existsSync(assetPath) || fs.statSync(assetPath).size === 0) {
        fail(`Required PWA icon is missing or empty: public/${name}`);
    }
}
for (const [name, expectedSize] of [
    ['apple-touch-icon.png', 180],
    ['pwa-192x192.png', 192],
    ['pwa-512x512.png', 512],
    ['maskable-icon-512x512.png', 512],
]) {
    const png = fs.readFileSync(path.join(pub, name));
    const hasPngSignature = png.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]));
    const width = png.length >= 24 ? png.readUInt32BE(16) : 0;
    const height = png.length >= 24 ? png.readUInt32BE(20) : 0;
    if (!hasPngSignature || width !== expectedSize || height !== expectedSize) {
        fail(`public/${name} must be a valid ${expectedSize}x${expectedSize} PNG.`);
    }
}
const favicon = fs.readFileSync(path.join(pub, 'favicon.ico'));
if (favicon.length < 22 || favicon.readUInt16LE(2) !== 1 || favicon.readUInt16LE(4) < 1) {
    fail('public/favicon.ico is not a valid icon file.');
}

const apacheRulesPath = path.join(pub, '.htaccess');
if (!fs.existsSync(apacheRulesPath)) fail('public/.htaccess is missing the PWA cache policy.');
const apacheRules = fs.readFileSync(apacheRulesPath, 'utf8');
if (!apacheRules.includes('no-cache, no-store, must-revalidate')
    || !apacheRules.includes('push-sw\\.js')
    || !apacheRules.includes('^(?!sw\\.js$|push-sw\\.js$)')
    || !apacheRules.includes('31536000, immutable')) {
    fail('public/.htaccess must keep the SPA/service workers revalidatable and only long-cache hashed assets.');
}

log('');
log('[build-frontend] published to public/');
log(`  public/assets files : ${countFiles(path.join(pub, 'assets'))}`);
log(`  public/assets size  : ${(dirBytes(path.join(pub, 'assets')) / 1024 / 1024).toFixed(2)} MB`);
log(`  entrypoint refs     : ${refs.length} (all present)`);
log(`  precached /assets/  : ${precacheCount}`);
log('[build-frontend] OK');
