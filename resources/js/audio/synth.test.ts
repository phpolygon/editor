import { describe, expect, it } from 'vitest';
import { synthTone, defaultSynth } from './synth';

function peak(samples: Float32Array): number {
    let max = 0;
    for (let i = 0; i < samples.length; i++) max = Math.max(max, Math.abs(samples[i]));
    return max;
}

describe('synthTone', () => {
    it('renders duration * sampleRate samples, all in range', () => {
        const s = synthTone({ ...defaultSynth(), duration: 0.1 }, 44100);
        expect(s.length).toBe(Math.ceil(0.1 * 44100));
        expect(peak(s)).toBeLessThanOrEqual(1);
    });

    it('starts near silence with a non-zero attack', () => {
        const s = synthTone({ ...defaultSynth(), attack: 0.05, duration: 0.3 }, 44100);
        expect(Math.abs(s[0])).toBeLessThan(0.05);
    });

    it('is audible overall', () => {
        expect(peak(synthTone({ ...defaultSynth(), duration: 0.2 }, 44100))).toBeGreaterThan(0.02);
    });
});
