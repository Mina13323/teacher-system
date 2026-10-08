import { describe, expect, it } from 'vitest';
import { createServerClock, SERVER_CLOCK_MAX_RTT_MS, SERVER_CLOCK_MAX_SAMPLES } from './serverClock';

describe('createServerClock', () => {
    it('uses the device clock until a sample arrives', () => {
        const clock = createServerClock({ now: () => 1_000 });
        expect(clock.offset()).toBe(0);
        expect(clock.serverNow()).toBe(1_000);
    });

    it('measures a fast device clock (which used to end exams early)', () => {
        // Device is 90 s ahead: sent at 100_000, server answered 10_050, back at 100_100.
        const clock = createServerClock({ now: () => 100_100 });
        expect(clock.observe(10_050, 100_000, 100_100)).toBe(true);
        expect(clock.offset()).toBe(-90_000);
        expect(clock.serverNow()).toBe(10_100);
    });

    it('measures a slow device clock', () => {
        const clock = createServerClock({ now: () => 0 });
        clock.observe(60_150, 100, 200);
        expect(clock.offset()).toBe(60_000);
    });

    it('prefers the sample with the shortest round trip', () => {
        const clock = createServerClock();
        clock.observe(5_000 + 1_000, 0, 2_000); // rtt 2000 → offset 5000
        clock.observe(5_000 + 10_020 + 7, 10_000, 10_040); // rtt 40 → offset 5007
        clock.observe(5_000 + 20_500 + 300, 20_000, 21_000); // rtt 1000 → offset 5300
        expect(clock.offset()).toBe(5_007);
    });

    it('ignores impossible or imprecise samples', () => {
        const clock = createServerClock();
        expect(clock.observe(NaN, 0, 10)).toBe(false);
        expect(clock.observe(1_000, 50, 10)).toBe(false); // negative round trip
        expect(clock.observe(1_000, 0, SERVER_CLOCK_MAX_RTT_MS + 1)).toBe(false);
        expect(clock.observe(undefined, 0, 10)).toBe(false);
        expect(clock.sampleCount()).toBe(0);
        expect(clock.offset()).toBe(0);
    });

    it('keeps only the most recent samples', () => {
        const clock = createServerClock();
        clock.observe(1_000_000, 0, 0); // very old, precise sample with a stale offset
        for (let i = 1; i <= SERVER_CLOCK_MAX_SAMPLES; i++) {
            clock.observe(i * 1_000 + 50 + 7, i * 1_000, i * 1_000 + 100);
        }
        expect(clock.sampleCount()).toBe(SERVER_CLOCK_MAX_SAMPLES);
        expect(clock.offset()).toBe(7);
    });
});
