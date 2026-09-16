// Shared, locale-aware date formatting so every screen renders dates the same
// way. The active language is read from <html lang>, which i18n/index.js keeps
// in sync, so Arabic and English both format natively instead of each view
// inventing its own options.

const FALLBACK = '\u2014';

/** The app's active locale, falling back to English. */
export function currentLocale() {
    if (typeof document !== 'undefined' && document.documentElement?.lang) {
        return document.documentElement.lang;
    }
    return 'en';
}

/** Normalise anything date-like to a Date, or null when unusable. */
function toDate(value) {
    if (!value) return null;
    const d = value instanceof Date ? value : new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Format a date-only value (no time). Empty/invalid input renders an em dash so
 * callers never have to hand-roll a guard.
 */
export function formatDate(value, options = { dateStyle: 'medium' }) {
    const d = toDate(value);
    if (!d) return FALLBACK;
    try {
        return new Intl.DateTimeFormat(currentLocale(), options).format(d);
    } catch {
        return d.toLocaleDateString();
    }
}

/** Format a date with its time — the standard for timestamps. */
export function formatDateTime(value, options = { dateStyle: 'medium', timeStyle: 'short' }) {
    return formatDate(value, options);
}

/** Format only the time component. */
export function formatTime(value) {
    return formatDate(value, { timeStyle: 'short' });
}
