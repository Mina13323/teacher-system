import './bootstrap';
import '../css/app.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import i18n, { applyDirection } from './i18n';
import { setUnauthorizedHandler } from './api/client';
import { useAuthStore } from './stores/auth';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);

// A sign-in revoked mid-session (moved to another device, logged out
// elsewhere, revoked by staff) goes back to the login page, which says why.
setUnauthorizedHandler((error) => {
    const auth = useAuthStore(pinia);
    if (!auth.handleUnauthorized(error)) return;
    const current = router.currentRoute.value;
    if (current.meta?.public || current.meta?.guest) return;
    router.push({ name: 'login', query: { redirect: current.fullPath } });
});

// Automatically reload the page when a stale chunk fails to load after a new deployment
window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    window.location.reload();
});

// Apply the persisted/browser locale direction (LTR for English, RTL for Arabic).
applyDirection(i18n.global.locale.value);

app.mount('#app');
