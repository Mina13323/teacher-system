import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [vue()],
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
