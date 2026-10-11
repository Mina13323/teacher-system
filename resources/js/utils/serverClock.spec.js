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

describe('needsResync', () => {
    function clocks() {
        const t = { wall: 1_000_000, mono: 5_000 };
        const clock = createServerClock({ now: () => t.wall, monotonic: () => t.mono });
        return { t, clock };
    }

    it('wants a sample until one arrives', () => {
        const { clock } = clocks();
        expect(clock.needsResync()).toBe(true);
    });

    it('needs no new sample while the device clock runs normally', () => {
        const { t, clock } = clocks();
        clock.observe(t.wall + 50, t.wall - 100, t.wall);
        // An hour passes on both clocks, with half a second of natural drift.
        t.wall += 3_600_000;
        t.mono += 3_600_000 - 500;
        expect(clock.needsResync()).toBe(false);
    });

    it('notices the device clock being changed and drops the stale offset on the next sample', () => {
        const { t, clock } = clocks();
        clock.observe(t.wall + 10_000 - 25, t.wall - 50, t.wall); // device 10 s behind, rtt 50
        expect(clock.offset()).toBe(10_000);

        // The student sets the device clock an hour back; only 30 s really passed.
        t.wall += 30_000 - 3_600_000;
        t.mono += 30_000;
        expect(clock.needsResync()).toBe(true);
        expect(clock.needsResync()).toBe(true); // stays wanted until a sample arrives

        // The new sample has a slower round trip than the old one, yet replaces it.
        clock.observe(t.wall + 3_610_000 - 500, t.wall - 1_000, t.wall);
        expect(clock.offset()).toBe(3_610_000);
        expect(clock.needsResync()).toBe(false);
    });

    it('notices a sleep during which the page clock paused', () => {
        const { t, clock } = clocks();
        clock.observe(t.wall, t.wall, t.wall);
        t.wall += 600_000; // ten minutes asleep
        t.mono += 10;
        expect(clock.needsResync()).toBe(true);
    });
});
