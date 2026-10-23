import { describe, expect, it } from 'vitest';
import { createPlaybackEventGate, isBlurIntoPlayer, PLAYBACK_EVENT_MIN_GAP_MS } from './playbackEventGate';

describe('createPlaybackEventGate', () => {
    it('sends each type at most once per minimum gap', () => {
        const gate = createPlaybackEventGate();
        expect(gate.allow('TAB_SWITCH', 0)).toBe(true);
        expect(gate.allow('TAB_SWITCH', 5_000)).toBe(false);
        expect(gate.allow('WINDOW_BLUR', 5_000)).toBe(true);
        expect(gate.allow('TAB_SWITCH', PLAYBACK_EVENT_MIN_GAP_MS)).toBe(true);
    });

    it('reports DevTools only when the heuristic turns on', () => {
        const gate = createPlaybackEventGate();
        expect(gate.devtoolsTurnedOn(false)).toBe(false);
        expect(gate.devtoolsTurnedOn(true)).toBe(true);
        expect(gate.devtoolsTurnedOn(true)).toBe(false);
        expect(gate.devtoolsTurnedOn(true)).toBe(false);
        expect(gate.devtoolsTurnedOn(false)).toBe(false);
        expect(gate.devtoolsTurnedOn(true)).toBe(true);
    });

    it('polling for ten minutes with DevTools open sends one report', () => {
        const gate = createPlaybackEventGate();
        let sent = 0;
        for (let t = 0; t < 600_000; t += 1_500) {
            if (gate.devtoolsTurnedOn(true) && gate.allow('DEVTOOLS_DETECTION', t)) sent++;
        }
        expect(sent).toBe(1);
    });
});

describe('isBlurIntoPlayer', () => {
    it('is true only for the player frame inside the player element', () => {
        const frame = { tagName: 'IFRAME' };
        const otherFrame = { tagName: 'IFRAME' };
        const body = { tagName: 'BODY' };
        const wrap = { contains: (el) => el === frame };
        expect(isBlurIntoPlayer(frame, wrap)).toBe(true);
        expect(isBlurIntoPlayer(otherFrame, wrap)).toBe(false);
        expect(isBlurIntoPlayer(body, { contains: () => true })).toBe(false);
        expect(isBlurIntoPlayer(null, wrap)).toBe(false);
    });
});
