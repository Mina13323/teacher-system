import './bootstrap';
import '../css/app.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import i18n, { applyDirection } from './i18n';

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.use(i18n);

// Automatically reload the page when a stale chunk fails to load after a new deployment
window.addEventListener('vite:preloadError', (event) => {
    event.preventDefault();
    window.location.reload();
});

// Apply the persisted/browser locale direction (LTR for English, RTL for Arabic).
applyDirection(i18n.global.locale.value);

app.mount('#app');
