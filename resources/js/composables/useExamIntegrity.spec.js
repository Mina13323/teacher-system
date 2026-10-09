import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const recordIntegrity = vi.fn(() => Promise.resolve({ counted: false }));
vi.mock('@/api', () => ({ student: { recordIntegrity: (...args) => recordIntegrity(...args) } }));

const { useExamIntegrity } = await import('./useExamIntegrity');

/** Minimal browser stand-ins: the composable only needs events and two flags. */
function installBrowser() {
    const doc = new EventTarget();
    doc.hidden = false;
    doc.fullscreenElement = null;
    globalThis.document = doc;
    globalThis.window = new EventTarget();
    return doc;
}

const RULES = {
    detect_tab_switch: true,
    detect_window_blur: true,
    fullscreen_required: true,
};

function sentTypes() {
    return recordIntegrity.mock.calls.map(([, payload]) => payload.event_type);
}

function count(type) {
    return sentTypes().filter((t) => t === type).length;
}

let doc;
let integrity;

function leaveTab() {
    doc.hidden = true;
    doc.dispatchEvent(new Event('visibilitychange'));
    window.dispatchEvent(new Event('blur'));
}

function returnToTab() {
    doc.hidden = false;
    doc.dispatchEvent(new Event('visibilitychange'));
    window.dispatchEvent(new Event('focus'));
}

function setFullscreen(on) {
    doc.fullscreenElement = on ? {} : null;
    doc.dispatchEvent(new Event('fullscreenchange'));
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-08T10:00:00Z'));
    // The composable registers an unmount hook; outside a component Vue only warns.
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    recordIntegrity.mockClear();
    doc = installBrowser();
    integrity = useExamIntegrity({ getAttemptId: () => 42, getRules: () => RULES });
    integrity.start();
});

afterEach(() => {
    integrity.stop();
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('integrity return-event collapse', () => {
    it('sends one WINDOW_FOCUS when visibilitychange and focus fire for the same return', () => {
        leaveTab();
        vi.advanceTimersByTime(3000);
        returnToTab();

        expect(count('TAB_SWITCH')).toBe(1);
        expect(count('WINDOW_FOCUS')).toBe(1);
    });

    it('collapses rapid returns while every separate departure window is still reported', () => {
        // 10 tab switches in 5 seconds: leave, come back 250 ms later, repeat.
        for (let i = 0; i < 10; i++) {
            leaveTab();
            vi.advanceTimersByTime(250);
            returnToTab();
            vi.advanceTimersByTime(250);
        }

        // Departures keep their existing 1.5 s burst collapse (unchanged).
        expect(count('TAB_SWITCH')).toBe(4);
        // Returns used to be 2 per switch (20); now one per burst window.
        expect(count('WINDOW_FOCUS')).toBe(4);
        expect(recordIntegrity).toHaveBeenCalledTimes(8);
    });

    it('collapses repeated FULLSCREEN_ENTER but reports every separate FULLSCREEN_EXIT', () => {
        setFullscreen(true);
        setFullscreen(true);
        setFullscreen(true);
        expect(count('FULLSCREEN_ENTER')).toBe(1);

        for (let i = 0; i < 3; i++) {
            vi.advanceTimersByTime(2000);
            setFullscreen(false);
            vi.advanceTimersByTime(100);
            setFullscreen(true);
        }

        expect(count('FULLSCREEN_EXIT')).toBe(3);
        expect(count('FULLSCREEN_ENTER')).toBe(4);
    });

    it('still reports a genuine violation that follows a collapsed return', () => {
        leaveTab();
        vi.advanceTimersByTime(100);
        returnToTab();
        returnToTab();
        vi.advanceTimersByTime(2000);
        leaveTab();

        expect(count('TAB_SWITCH')).toBe(2);
        expect(count('WINDOW_FOCUS')).toBe(1);
    });

    it('reports a later, separate return again', () => {
        leaveTab();
        vi.advanceTimersByTime(100);
        returnToTab();
        vi.advanceTimersByTime(2000);
        leaveTab();
        vi.advanceTimersByTime(100);
        returnToTab();

        expect(count('WINDOW_FOCUS')).toBe(2);
    });

    it('does not count return events as violations', () => {
        returnToTab();
        setFullscreen(true);

        expect(integrity.violations.value).toBe(0);

        setFullscreen(false);
        expect(integrity.violations.value).toBe(1);
        expect(count('FULLSCREEN_EXIT')).toBe(1);
    });

    it('keeps a visible-page blur as a WINDOW_BLUR violation', () => {
        window.dispatchEvent(new Event('blur'));
        vi.advanceTimersByTime(1600);

        expect(count('WINDOW_BLUR')).toBe(1);
        expect(integrity.violations.value).toBe(1);
    });
});
