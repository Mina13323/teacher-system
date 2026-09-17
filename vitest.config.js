import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Standalone vitest config.
 *
 * Deliberately separate from vite.config.js: the app config pulls in the
 * Laravel and PWA plugins, which only make sense for a browser build. Unit
 * tests need nothing but the "@" alias and a node environment.
 */
export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },

    test: {
        environment: 'node',
        include: ['resources/js/**/*.spec.js'],
        globals: false,
    },
});
