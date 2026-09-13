import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        vue(),
        VitePWA({
            registerType: 'prompt',
            includeAssets: ['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'pwa-192x192.png', 'pwa-512x512.png', 'maskable-icon-512x512.png'],
            manifest: {
                name: 'Atlas Academy — Geography & History',
                short_name: 'Atlas Academy',
                description: 'A modern Geography and History learning platform.',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                background_color: '#fdfbf7',
                theme_color: '#c84b1a',
                orientation: 'any',
                icons: [
                    {
                        src: '/pwa-192x192.png',
                        sizes: '192x192',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/pwa-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'any',
                    },
                    {
                        src: '/maskable-icon-512x512.png',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable',
                    },
                ],
                shortcuts: [
                    {
                        name: 'Courses',
                        short_name: 'Courses',
                        url: '/courses',
                        icons: [{ src: '/pwa-192x192.png', sizes: '192x192' }],
                    },
                ],
            },
            workbox: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
                navigateFallback: '/index.html',
                navigateFallbackDenylist: [/^\/api\/.*/, /^\/storage\/.*/],
                runtimeCaching: [
                    {
                        // STRICT SECURITY: Never cache authenticated API endpoints, Sanctum tokens, or protected media
                        urlPattern: /^\/api\/.*/,
                        handler: 'NetworkOnly',
                    },
                    {
                        // Cache static Google Fonts safely
                        urlPattern: /^https:\/\/fonts\.(googleapis|gstatic)\.com\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'google-fonts-cache',
                            expiration: {
                                maxEntries: 10,
                                maxAgeSeconds: 60 * 60 * 24 * 365,
                            },
                        },
                    },
                ],
            },
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        // Allow the sandboxed preview proxy (and localhost) past Vite 6's host check.
        allowedHosts: ['.e2b.app', 'localhost', '127.0.0.1'],
        // Proxy API calls to the Laravel backend so the SPA can call the exact
        // same `/api/v1/...` contracts it will use in production (same-origin).
        proxy: {
            '/api': {
                target: process.env.VITE_API_PROXY || 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
    },
});
