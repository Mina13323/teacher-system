/**
 * `<input type="datetime-local">` holds a time in the browser's own timezone
 * without an offset; the server takes ISO-8601 and works in UTC. These
 * convert between the two, so the teacher's local offset never leaks into a
 * stored window.
 */

/** ISO-8601 -> `YYYY-MM-DDTHH:mm` in the browser's local timezone ('' when empty or invalid). */
export function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** `datetime-local` value -> ISO-8601 UTC, or null when empty or invalid. */
export function toIso(local) {
    if (!local) return null;
    const d = new Date(local);
    return Number.isNaN(d.getTime()) ? null : d.toISOString();
}
