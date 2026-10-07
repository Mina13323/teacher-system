// Moves the Vite/PWA build into Laravel's public/ folder for deployment.
//
//   node scripts/publish-build.mjs clean  -> remove previously published build files from public/
//   node scripts/publish-build.mjs copy   -> copy the fresh build from dist/ into public/
//
// `clean` runs before every build: Vite copies public/ into dist/, so stale
// chunks left in public/assets would otherwise end up in the new bundle and
// in the service worker precache list.
import { cpSync, existsSync, readdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('..', import.meta.url));
const dist = join(root, 'dist');
const pub = join(root, 'public');

const isGenerated = (name) =>
    name === 'assets' ||
    name === 'index.html' ||
    name === 'sw.js' ||
    name === 'manifest.webmanifest' ||
    /^workbox-.*\.js$/.test(name);

const mode = process.argv[2];

if (mode === 'clean') {
    for (const name of readdirSync(pub).filter(isGenerated)) {
        rmSync(join(pub, name), { recursive: true, force: true });
    }
} else if (mode === 'copy') {
    if (!existsSync(join(dist, 'index.html'))) {
        console.error('dist/index.html not found; run `npm run build` first.');
        process.exit(1);
    }
    for (const name of readdirSync(dist).filter(isGenerated)) {
        cpSync(join(dist, name), join(pub, name), { recursive: true });
    }
    console.log('Published dist/ build into public/.');
} else {
    console.error('Usage: node scripts/publish-build.mjs <clean|copy>');
    process.exit(1);
}
