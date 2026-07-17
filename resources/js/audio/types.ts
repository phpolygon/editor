export type SfxWave = 'square' | 'sawtooth' | 'sine' | 'triangle' | 'noise';

/**
 * Parameters for a retro sound effect, in the spirit of DrPetter's sfxr /
 * jsfxr. All values are normalized 0..1 (or -1..1 where a value can go both
 * ways) so a single "randomize" pass can perturb them uniformly. The synth in
 * `sfxr.ts` maps them to concrete Hz / seconds.
 */
export interface SfxParams {
    wave: SfxWave;

    // Amplitude envelope (0..1, squared → seconds inside the synth).
    attack: number;
    sustain: number;
    punch: number; // extra gain at the start of the sustain phase
    decay: number;

    // Pitch.
    freq: number; // start frequency, 0..1
    freqMin: number; // floor the slide can't go below, 0..1
    slide: number; // -1..1, per-sample multiplicative pitch ramp
    deltaSlide: number; // -1..1, change of slide over time

    // Vibrato.
    vibratoDepth: number; // 0..1
    vibratoSpeed: number; // 0..1

    // Arpeggio (a single pitch jump partway through).
    arpMod: number; // -1..1 pitch multiplier applied at arpSpeed
    arpSpeed: number; // 0..1, higher = later/never

    // Square-wave duty cycle.
    duty: number; // 0..1
    dutySweep: number; // -1..1

    // Low/high-pass one-pole filters.
    lpCutoff: number; // 0..1 (1 = open)
    lpSweep: number; // -1..1
    hpCutoff: number; // 0..1 (0 = open)

    volume: number; // 0..1 master gain
}

export type SynthWave = 'sine' | 'square' | 'sawtooth' | 'triangle';

/**
 * Parameters for the general-purpose subtractive synth (the "Synth" tab):
 * an oscillator with an ADSR envelope, a resonant-ish low-pass, and a pitch
 * LFO. Rendered offline for export and played live in the browser.
 */
export interface SynthParams {
    wave: SynthWave;
    frequency: number; // Hz
    duration: number; // seconds

    attack: number; // seconds
    decay: number; // seconds
    sustain: number; // 0..1 level
    release: number; // seconds

    cutoff: number; // Hz, low-pass
    lfoRate: number; // Hz, pitch vibrato
    lfoDepth: number; // 0..1 → semitone-ish depth

    volume: number; // 0..1
}
