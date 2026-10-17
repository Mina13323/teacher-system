import { describe, expect, it } from 'vitest';
import { createUpdateCheckGate, UPDATE_CHECK_MIN_GAP_MS } from './pwaUpdateGate';

describe('createUpdateCheckGate', () => {
    it('runs the first check and spaces the following focus-triggered ones', () => {
        let t = 0;
        const gate = createUpdateCheckGate({ now: () => t });
        expect(gate.allow()).toBe(true);
        t += 30_000; // the student returns to the tab
        expect(gate.allow()).toBe(false);
        t += UPDATE_CHECK_MIN_GAP_MS;
        expect(gate.allow()).toBe(true);
    });

    it('always runs a forced check (registration, back online, the periodic timer)', () => {
        let t = 0;
        const gate = createUpdateCheckGate({ now: () => t });
        gate.allow();
        t += 1_000;
        expect(gate.allow({ forced: true })).toBe(true);
        t += 1_000;
        expect(gate.allow()).toBe(false); // the forced check counts as the latest one
    });

    it('cuts an hour of frequent tab returns to at most seven checks', () => {
        let t = 0;
        let checks = 0;
        const gate = createUpdateCheckGate({ now: () => t });
        for (; t <= 60 * 60_000; t += 20_000) {
            if (gate.allow()) checks += 1; // a return every 20 s, two events each
            if (gate.allow()) checks += 1;
        }
        expect(checks).toBeLessThanOrEqual(7);
    });
});
