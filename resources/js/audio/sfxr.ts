import type { SfxParams, SfxWave } from './types';

const TAU = Math.PI * 2;

/** Neutral starting point — a short square blip. */
export function defaultSfx(): SfxParams {
    return {
        wave: 'square',
        attack: 0,
        sustain: 0.2,
        punch: 0,
        decay: 0.3,
        freq: 0.3,
        freqMin: 0,
        slide: 0,
        deltaSlide: 0,
        vibratoDepth: 0,
        vibratoSpeed: 0,
        arpMod: 0,
        arpSpeed: 0.5,
        duty: 0.5,
        dutySweep: 0,
        lpCutoff: 1,
        lpSweep: 0,
        hpCutoff: 0,
        volume: 0.5,
    };
}

function clamp(v: number, lo: number, hi: number): number {
    return v < lo ? lo : v > hi ? hi : v;
}

/** Map a normalized freq param (0..1) to Hz on a perceptual-ish curve. */
function toHz(freq01: number): number {
    return 40 + freq01 * freq01 * 7000;
}

/**
 * Synthesize a sound effect to mono float samples in [-1, 1]. Phase-accumulator
 * oscillator with an AD(S) envelope, pitch slide, vibrato, a single arpeggio
 * jump, duty-cycle sweep for squares, and one-pole low/high-pass filters.
 */
export function synthSfx(p: SfxParams, sampleRate = 44100): Float32Array {
    const attackT = p.attack * p.attack * 1.2;
    const sustainT = p.sustain * p.sustain * 1.5;
    const decayT = p.decay * p.decay * 1.8;
    const totalT = Math.max(0.02, attackT + sustainT + decayT);
    const n = Math.ceil(totalT * sampleRate);
    const out = new Float32Array(n);
    const dt = 1 / sampleRate;

    let freq = toHz(p.freq);
    const minFreq = toHz(p.freqMin);
    // Per-sample multiplicative slide + its own drift (deltaSlide).
    let slide = 1 + p.slide * 0.0006;
    const dSlide = p.deltaSlide * 0.0000008;

    const arpAt = p.arpMod !== 0 ? attackT + sustainT * (0.1 + (1 - p.arpSpeed) * 0.8) : Infinity;
    const arpFactor = p.arpMod >= 0 ? 1 + p.arpMod * 2 : 1 / (1 - p.arpMod);
    let arped = false;

    let duty = clamp(p.duty, 0.01, 0.99);
    const dutyStep = p.dutySweep * 0.00002;

    // One-pole low-pass state; cutoff coefficient derived from lpCutoff.
    let lpVal = 0;
    let lpCut = clamp(p.lpCutoff, 0, 1);
    const lpStep = p.lpSweep * 0.00003;
    // One-pole high-pass state.
    let hpVal = 0;
    const hpCoef = p.hpCutoff * p.hpCutoff * 0.1;

    let phase = 0;
    let noiseVal = randNoise();

    for (let i = 0; i < n; i++) {
        const t = i * dt;

        // Envelope.
        let amp: number;
        if (t < attackT) {
            amp = attackT > 0 ? t / attackT : 1;
        } else if (t < attackT + sustainT) {
            const s = sustainT > 0 ? (t - attackT) / sustainT : 1;
            amp = 1 + p.punch * (1 - s); // punch decays across sustain
        } else {
            const d = decayT > 0 ? (t - attackT - sustainT) / decayT : 1;
            amp = 1 - d;
        }
        amp = clamp(amp, 0, 2);

        // Pitch evolution.
        slide += dSlide;
        freq *= slide;
        if (!arped && t >= arpAt) {
            freq *= arpFactor;
            arped = true;
        }
        let f = freq;
        if (p.vibratoDepth > 0) {
            f *= 1 + p.vibratoDepth * 0.5 * Math.sin(TAU * (p.vibratoSpeed * 50) * t);
        }
        f = clamp(f, minFreq, sampleRate / 2.2);

        // Advance oscillator.
        const prevPhase = phase;
        phase += f * dt;
        const crossed = Math.floor(phase) > Math.floor(prevPhase);
        const ph = phase - Math.floor(phase);
        if (crossed) noiseVal = randNoise();

        duty = clamp(duty + dutyStep, 0.01, 0.99);

        let sample = oscillator(p.wave, ph, duty, noiseVal);

        // Filters.
        if (lpCut < 1) {
            lpCut = clamp(lpCut + lpStep, 0, 1);
            const a = lpCut * lpCut;
            lpVal += (sample - lpVal) * a;
            sample = lpVal;
        }
        if (hpCoef > 0) {
            hpVal += sample - hpVal * hpCoef - hpVal * 0.0; // simple leaky HP
            sample = sample - hpVal * hpCoef;
        }

        out[i] = clamp(sample * amp * p.volume, -1, 1);
    }

    return out;
}

function oscillator(wave: SfxWave, ph: number, duty: number, noiseVal: number): number {
    switch (wave) {
        case 'square':
            return ph < duty ? 1 : -1;
        case 'sawtooth':
            return 1 - ph * 2;
        case 'triangle':
            return 1 - Math.abs(ph - 0.5) * 4;
        case 'sine':
            return Math.sin(TAU * ph);
        case 'noise':
            return noiseVal;
    }
}

function randNoise(): number {
    return Math.random() * 2 - 1;
}

// ── Presets ────────────────────────────────────────────────────────────────

export type SfxPresetName =
    | 'pickup'
    | 'laser'
    | 'explosion'
    | 'powerup'
    | 'hit'
    | 'jump'
    | 'blip';

export const SFX_PRESETS: Record<SfxPresetName, () => SfxParams> = {
    pickup: () => ({ ...defaultSfx(), wave: 'square', freq: 0.4, sustain: 0.08, decay: 0.35, arpMod: 0.55, arpSpeed: 0.55, punch: 0.3 }),
    laser: () => ({ ...defaultSfx(), wave: 'sawtooth', freq: 0.6, sustain: 0.1, decay: 0.3, slide: -0.35, duty: 0.4, dutySweep: -0.2 }),
    explosion: () => ({ ...defaultSfx(), wave: 'noise', freq: 0.25, sustain: 0.18, punch: 0.5, decay: 0.5, slide: -0.15, lpCutoff: 0.7, lpSweep: -0.25 }),
    powerup: () => ({ ...defaultSfx(), wave: 'square', freq: 0.3, sustain: 0.2, decay: 0.35, slide: 0.25, vibratoDepth: 0.25, vibratoSpeed: 0.5 }),
    hit: () => ({ ...defaultSfx(), wave: 'noise', freq: 0.35, sustain: 0.05, decay: 0.22, slide: -0.35, lpCutoff: 0.55 }),
    jump: () => ({ ...defaultSfx(), wave: 'square', freq: 0.3, sustain: 0.1, decay: 0.25, slide: 0.3, duty: 0.55 }),
    blip: () => ({ ...defaultSfx(), wave: 'square', freq: 0.5, sustain: 0.03, decay: 0.08, duty: 0.5 }),
};

export const SFX_PRESET_NAMES = Object.keys(SFX_PRESETS) as SfxPresetName[];

/** Fully random effect — every field jittered. Great for exploration. */
export function randomizeSfx(): SfxParams {
    const waves: SfxWave[] = ['square', 'sawtooth', 'sine', 'triangle', 'noise'];
    const r = () => Math.random();
    const rs = () => Math.random() * 2 - 1;
    return {
        wave: waves[Math.floor(Math.random() * waves.length)],
        attack: r() * 0.3,
        sustain: 0.05 + r() * 0.4,
        punch: r() * 0.6,
        decay: 0.1 + r() * 0.5,
        freq: 0.15 + r() * 0.6,
        freqMin: r() * 0.2,
        slide: rs() * 0.5,
        deltaSlide: rs() * 0.3,
        vibratoDepth: r() * 0.5,
        vibratoSpeed: r(),
        arpMod: rs() * 0.6,
        arpSpeed: r(),
        duty: r(),
        dutySweep: rs() * 0.3,
        lpCutoff: 0.5 + r() * 0.5,
        lpSweep: rs() * 0.3,
        hpCutoff: r() * 0.3,
        volume: 0.5,
    };
}

/** Small perturbation of an existing effect (keeps its character). */
export function mutateSfx(p: SfxParams, amount = 0.1): SfxParams {
    const jitter = (v: number, lo = 0, hi = 1) => clamp(v + (Math.random() * 2 - 1) * amount, lo, hi);
    return {
        ...p,
        attack: jitter(p.attack),
        sustain: jitter(p.sustain),
        punch: jitter(p.punch),
        decay: jitter(p.decay),
        freq: jitter(p.freq),
        slide: jitter(p.slide, -1, 1),
        vibratoDepth: jitter(p.vibratoDepth),
        arpMod: jitter(p.arpMod, -1, 1),
        duty: jitter(p.duty),
        lpCutoff: jitter(p.lpCutoff),
    };
}
