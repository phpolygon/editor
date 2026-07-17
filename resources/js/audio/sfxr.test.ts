import { describe, expect, it } from 'vitest';
import { synthSfx, defaultSfx, SFX_PRESETS, SFX_PRESET_NAMES, randomizeSfx, mutateSfx } from './sfxr';

function peak(samples: Float32Array): number {
    let max = 0;
    for (let i = 0; i < samples.length; i++) max = Math.max(max, Math.abs(samples[i]));
    return max;
}

function inRange(samples: Float32Array): boolean {
    for (let i = 0; i < samples.length; i++) {
        if (samples[i] < -1 || samples[i] > 1) return false;
    }
    return true;
}

describe('synthSfx', () => {
    it('renders non-silent, in-range audio for a square blip', () => {
        const s = synthSfx({ ...defaultSfx(), wave: 'square' });
        expect(s.length).toBeGreaterThan(100);
        expect(inRange(s)).toBe(true);
        expect(peak(s)).toBeGreaterThan(0.05);
    });

    it('renders non-empty audio for every preset', () => {
        for (const name of SFX_PRESET_NAMES) {
            const s = synthSfx(SFX_PRESETS[name]());
            expect(s.length, name).toBeGreaterThan(0);
            expect(inRange(s), name).toBe(true);
        }
    });
});

describe('randomize / mutate', () => {
    it('randomizeSfx yields a valid parameter set', () => {
        const r = randomizeSfx();
        expect(r.freq).toBeGreaterThanOrEqual(0);
        expect(r.freq).toBeLessThanOrEqual(1);
        expect(synthSfx(r).length).toBeGreaterThan(0);
    });

    it('mutateSfx keeps values within their bounds', () => {
        const m = mutateSfx(defaultSfx(), 0.2);
        expect(m.freq).toBeGreaterThanOrEqual(0);
        expect(m.freq).toBeLessThanOrEqual(1);
        expect(m.slide).toBeGreaterThanOrEqual(-1);
        expect(m.slide).toBeLessThanOrEqual(1);
    });
});
