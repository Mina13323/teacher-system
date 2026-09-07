import { createI18n } from 'vue-i18n';
import en from './messages/en';
import ar from './messages/ar';

export const SUPPORTED = ['en', 'ar'];
export const LABELS = { en: 'English', ar: 'العربية' };

/**
 * Resolve the initial locale. Prefer a persisted choice, otherwise match the
 * browser language (defaulting to English). Only the two supported locales are
 * ever returned.
 */
export function resolveLang() {
    try {
        const stored = localStorage.getItem('app.lang');
        if (stored && SUPPORTED.includes(stored)) return stored;
    } catch {
        /* storage unavailable */
    }
    const nav = (navigator.language || 'en').toLowerCase();
    return nav.startsWith('ar') ? 'ar' : 'en';
}

export function applyDirection(code) {
    const dir = code === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('dir', dir);
    document.documentElement.setAttribute('lang', code);
}

const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: resolveLang(),
    fallbackLocale: 'en',
    messages: { en, ar },
});

export function setLocale(lang) {
    const code = SUPPORTED.includes(lang) ? lang : 'en';
    i18n.global.locale.value = code;
    try {
        localStorage.setItem('app.lang', code);
    } catch {
        /* storage unavailable */
    }
    applyDirection(code);
}

export default i18n;
