import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import type { SfxParams, SynthParams } from '@/audio/types';
import {
    defaultSfx,
    synthSfx,
    SFX_PRESETS,
    randomizeSfx,
    mutateSfx,
    type SfxPresetName,
} from '@/audio/sfxr';
import { defaultSynth, synthTone } from '@/audio/synth';
import { encodeWav, arrayBufferToBase64 } from '@/audio/wav';
import { playSamples, stop } from '@/audio/player';
import { saveAudio } from '@/bridge/commands';

const SAMPLE_RATE = 44100;

/**
 * State for the wave synthesizer workspace: the SFX/Synth mode, the two
 * parameter sets, and the derived rendered samples. Rendering is a cheap pure
 * function (`synthSfx`/`synthTone`), so `samples` is a computed that re-renders
 * only when parameters change — good enough to drive live waveform previews.
 */
export const useAudioEditorStore = defineStore('audioEditor', () => {
    const mode = ref<'sfx' | 'synth'>('sfx');
    const sfx = ref<SfxParams>(defaultSfx());
    const synth = ref<SynthParams>(defaultSynth());
    const currentPreset = ref<SfxPresetName | null>(null);
    const name = ref('sfx');

    const samples = computed(() =>
        mode.value === 'sfx'
            ? synthSfx(sfx.value, SAMPLE_RATE)
            : synthTone(synth.value, SAMPLE_RATE),
    );

    const sampleRate = SAMPLE_RATE;

    function applyPreset(preset: SfxPresetName) {
        mode.value = 'sfx';
        sfx.value = SFX_PRESETS[preset]();
        currentPreset.value = preset;
        name.value = preset;
    }

    function randomize() {
        mode.value = 'sfx';
        sfx.value = randomizeSfx();
        currentPreset.value = null;
        name.value = 'random';
    }

    function mutate() {
        sfx.value = mutateSfx(sfx.value);
        currentPreset.value = null;
    }

    async function play() {
        await playSamples(samples.value, SAMPLE_RATE);
    }

    function stopPlayback() {
        stop();
    }

    /** Encode the current sound to a WAV and persist it under assets/audio/. */
    async function save() {
        const wav = encodeWav(samples.value, SAMPLE_RATE);
        const base64 = arrayBufferToBase64(wav);
        return saveAudio(name.value.trim() || 'sfx', base64);
    }

    return {
        mode,
        sfx,
        synth,
        currentPreset,
        name,
        samples,
        sampleRate,
        applyPreset,
        randomize,
        mutate,
        play,
        stopPlayback,
        save,
    };
});
