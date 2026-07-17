import type { SynthParams, SynthWave } from './types';

const TAU = Math.PI * 2;

export function defaultSynth(): SynthParams {
    return {
        wave: 'sine',
        frequency: 440,
        duration: 0.6,
        attack: 0.01,
        decay: 0.12,
        sustain: 0.6,
        release: 0.18,
        cutoff: 8000,
        lfoRate: 5,
        lfoDepth: 0,
        volume: 0.5,
    };
}

function clamp(v: number, lo: number, hi: number): number {
    return v < lo ? lo : v > hi ? hi : v;
}

function osc(wave: SynthWave, ph: number): number {
    switch (wave) {
        case 'sine':
            return Math.sin(TAU * ph);
        case 'square':
            return ph < 0.5 ? 1 : -1;
        case 'sawtooth':
            return 1 - ph * 2;
        case 'triangle':
            return 1 - Math.abs(ph - 0.5) * 4;
    }
}

/**
 * Render a subtractive-synth tone (oscillator → ADSR → one-pole low-pass, with
 * a pitch LFO) to mono float samples. Used both for export and to draw the
 * waveform preview.
 */
export function synthTone(p: SynthParams, sampleRate = 44100): Float32Array {
    const n = Math.max(1, Math.ceil(p.duration * sampleRate));
    const out = new Float32Array(n);
    const dt = 1 / sampleRate;
    const lpCoef = clamp((p.cutoff / (sampleRate / 2)) ** 0.5, 0.001, 1);

    let phase = 0;
    let lp = 0;

    for (let i = 0; i < n; i++) {
        const t = i * dt;

        // ADSR: attack → decay → sustain, release folded in near the end.
        let env: number;
        if (t < p.attack) {
            env = p.attack > 0 ? t / p.attack : 1;
        } else if (t < p.attack + p.decay) {
            const d = p.decay > 0 ? (t - p.attack) / p.decay : 1;
            env = 1 - (1 - p.sustain) * d;
        } else {
            env = p.sustain;
        }
        const toEnd = p.duration - t;
        if (toEnd < p.release && p.release > 0) {
            env *= clamp(toEnd / p.release, 0, 1);
        }

        const lfo = p.lfoDepth > 0 ? 1 + p.lfoDepth * 0.06 * Math.sin(TAU * p.lfoRate * t) : 1;
        phase += (p.frequency * lfo) * dt;
        const ph = phase - Math.floor(phase);

        let s = osc(p.wave, ph);
        lp += (s - lp) * lpCoef;
        s = lp;

        out[i] = clamp(s * env * p.volume, -1, 1);
    }

    return out;
}
