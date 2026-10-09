import { describe, expect, it } from 'vitest';
import {
    RESULT_NOTICE_REFRESH_WINDOW_MS,
    START_PACING_BURST_WINDOW_MS,
    START_PACING_MAX_MS,
    START_PACING_SAFETY_MS,
    TIME_UP_SUBMIT_SPREAD_MS,
    resultNoticeRefreshDelayMs,
    startPacingDelayMs,
    timeUpSubmitDelayMs,
} from './examRequestPacing';

const OPENS = Date.parse('2026-10-08T10:00:00Z');
const at = (seconds) => OPENS + seconds * 1000;
const iso = (ms) => new Date(ms).toISOString();
const TOP = () => 0.999999;

describe('deadline submit spread', () => {
    it('spreads the time-up submit over 45-60 s', () => {
        expect(TIME_UP_SUBMIT_SPREAD_MS).toBeGreaterThanOrEqual(45_000);
        expect(TIME_UP_SUBMIT_SPREAD_MS).toBeLessThanOrEqual(60_000);
        expect(timeUpSubmitDelayMs(() => 0)).toBe(0);
        expect(timeUpSubmitDelayMs(TOP)).toBeLessThanOrEqual(TIME_UP_SUBMIT_SPREAD_MS);
    });

    it('spreads 500 submits to a rate the shared host accepts', () => {
        expect(500 / (TIME_UP_SUBMIT_SPREAD_MS / 1000)).toBeLessThanOrEqual(12);
    });
});

describe('result notice refresh', () => {
    it('waits a random 20-90 s', () => {
        expect(resultNoticeRefreshDelayMs(() => 0)).toBe(RESULT_NOTICE_REFRESH_WINDOW_MS[0]);
        expect(resultNoticeRefreshDelayMs(TOP)).toBeLessThanOrEqual(RESULT_NOTICE_REFRESH_WINDOW_MS[1]);
        expect(RESULT_NOTICE_REFRESH_WINDOW_MS[0]).toBeGreaterThanOrEqual(20_000);
    });
});

describe('start pacing', () => {
    it('waits up to the maximum right after the exam opens', () => {
        const delay = startPacingDelayMs({ nowMs: at(5), startsAt: iso(OPENS), random: TOP });
        expect(delay).toBeGreaterThan(START_PACING_MAX_MS - 10);
        expect(delay).toBeLessThanOrEqual(START_PACING_MAX_MS);
        expect(startPacingDelayMs({ nowMs: at(5), startsAt: iso(OPENS), random: () => 0 })).toBe(0);
    });

    it('does not wait for an exam without an opening time', () => {
        expect(startPacingDelayMs({ nowMs: at(5), startsAt: null, random: TOP })).toBe(0);
    });

    it('does not wait outside the opening burst', () => {
        expect(startPacingDelayMs({ nowMs: at(-10), startsAt: iso(OPENS), random: TOP })).toBe(0);
        expect(startPacingDelayMs({ nowMs: OPENS + START_PACING_BURST_WINDOW_MS + 1, startsAt: iso(OPENS), random: TOP })).toBe(0);
    });

    it('never costs exam time when the window is exactly the duration', () => {
        // 60-minute exam in a 60-minute window: any wait would cut the attempt.
        expect(startPacingDelayMs({
            nowMs: at(5), startsAt: iso(OPENS), endsAt: iso(at(3600)), durationMinutes: 60, random: TOP,
        })).toBe(0);
    });

    it('caps the wait to the slack the window leaves', () => {
        // 60-minute exam in a 60.5-minute window, 5 s after opening: 25 s of
        // slack, minus the safety margin.
        const slack = at(3630) - at(5) - 3600_000 - START_PACING_SAFETY_MS;
        const delay = startPacingDelayMs({
            nowMs: at(5), startsAt: iso(OPENS), endsAt: iso(at(3630)), durationMinutes: 60, random: TOP,
        });
        expect(delay).toBeLessThanOrEqual(slack);
        expect(delay).toBeGreaterThan(slack - 10);
    });

    it('uses the full wait when the window is long', () => {
        const delay = startPacingDelayMs({
            nowMs: at(5), startsAt: iso(OPENS), endsAt: iso(at(7200)), durationMinutes: 60, random: TOP,
        });
        expect(delay).toBeGreaterThan(START_PACING_MAX_MS - 10);
    });

    it('treats unreadable times as no pacing', () => {
        expect(startPacingDelayMs({ nowMs: at(5), startsAt: 'not a date', random: TOP })).toBe(0);
        expect(startPacingDelayMs({ nowMs: at(5), startsAt: iso(OPENS), endsAt: 'nope', durationMinutes: 30, random: TOP })).toBe(0);
        expect(startPacingDelayMs({ nowMs: Number.NaN, startsAt: iso(OPENS), random: TOP })).toBe(0);
    });

    it('spreads 500 starts to about 11 a second', () => {
        expect(500 / (START_PACING_MAX_MS / 1000)).toBeLessThanOrEqual(12);
    });
});
