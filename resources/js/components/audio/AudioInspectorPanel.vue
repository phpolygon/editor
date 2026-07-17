<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Slider from '@/components/ui/Slider.vue';
import Select from '@/components/ui/Select.vue';
import { useAudioEditorStore } from '@/stores/audioEditor';

const store = useAudioEditorStore();

const sfxWaves = [
    { value: 'square', label: 'Square' },
    { value: 'sawtooth', label: 'Sawtooth' },
    { value: 'sine', label: 'Sine' },
    { value: 'triangle', label: 'Triangle' },
    { value: 'noise', label: 'Noise' },
] as const;

const synthWaves = [
    { value: 'sine', label: 'Sine' },
    { value: 'square', label: 'Square' },
    { value: 'sawtooth', label: 'Sawtooth' },
    { value: 'triangle', label: 'Triangle' },
] as const;

const hz = (v: number) => `${v.toFixed(0)}Hz`;
const secs = (v: number) => `${v.toFixed(2)}s`;
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Parameters" />
        <div class="flex-1 overflow-y-auto p-3 flex flex-col gap-1.5">
            <!-- ── SFX mode ── -->
            <template v-if="store.mode === 'sfx'">
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-28 shrink-0 text-editor-muted">Waveform</span>
                    <Select
                        class="flex-1"
                        :model-value="store.sfx.wave"
                        :options="sfxWaves"
                        @update:model-value="store.sfx.wave = $event"
                    />
                </label>

                <p class="section">Envelope</p>
                <Slider v-model="store.sfx.attack" label="Attack" />
                <Slider v-model="store.sfx.sustain" label="Sustain" />
                <Slider v-model="store.sfx.punch" label="Punch" />
                <Slider v-model="store.sfx.decay" label="Decay" />

                <p class="section">Pitch</p>
                <Slider v-model="store.sfx.freq" label="Frequency" />
                <Slider v-model="store.sfx.freqMin" label="Min cutoff" />
                <Slider v-model="store.sfx.slide" label="Slide" :min="-1" />
                <Slider v-model="store.sfx.deltaSlide" label="Delta slide" :min="-1" />

                <p class="section">Vibrato</p>
                <Slider v-model="store.sfx.vibratoDepth" label="Depth" />
                <Slider v-model="store.sfx.vibratoSpeed" label="Speed" />

                <p class="section">Arpeggio</p>
                <Slider v-model="store.sfx.arpMod" label="Mod" :min="-1" />
                <Slider v-model="store.sfx.arpSpeed" label="Speed" />

                <p class="section">Tone</p>
                <Slider v-model="store.sfx.duty" label="Duty cycle" />
                <Slider v-model="store.sfx.dutySweep" label="Duty sweep" :min="-1" />

                <p class="section">Filter</p>
                <Slider v-model="store.sfx.lpCutoff" label="LP cutoff" />
                <Slider v-model="store.sfx.lpSweep" label="LP sweep" :min="-1" />
                <Slider v-model="store.sfx.hpCutoff" label="HP cutoff" />

                <p class="section">Master</p>
                <Slider v-model="store.sfx.volume" label="Volume" />
            </template>

            <!-- ── Synth mode ── -->
            <template v-else>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-28 shrink-0 text-editor-muted">Waveform</span>
                    <Select
                        class="flex-1"
                        :model-value="store.synth.wave"
                        :options="synthWaves"
                        @update:model-value="store.synth.wave = $event"
                    />
                </label>

                <p class="section">Oscillator</p>
                <Slider v-model="store.synth.frequency" label="Frequency" :min="20" :max="2000" :step="1" :format="hz" />
                <Slider v-model="store.synth.duration" label="Duration" :min="0.05" :max="3" :step="0.01" :format="secs" />

                <p class="section">Envelope (ADSR)</p>
                <Slider v-model="store.synth.attack" label="Attack" :min="0" :max="2" :step="0.01" :format="secs" />
                <Slider v-model="store.synth.decay" label="Decay" :min="0" :max="2" :step="0.01" :format="secs" />
                <Slider v-model="store.synth.sustain" label="Sustain" />
                <Slider v-model="store.synth.release" label="Release" :min="0" :max="2" :step="0.01" :format="secs" />

                <p class="section">Filter</p>
                <Slider v-model="store.synth.cutoff" label="LP cutoff" :min="100" :max="15000" :step="100" :format="hz" />

                <p class="section">LFO (vibrato)</p>
                <Slider v-model="store.synth.lfoRate" label="Rate" :min="0" :max="20" :step="0.1" :format="hz" />
                <Slider v-model="store.synth.lfoDepth" label="Depth" />

                <p class="section">Master</p>
                <Slider v-model="store.synth.volume" label="Volume" />
            </template>
        </div>
    </div>
</template>

<style scoped>
.section {
    font-size: 10px;
    line-height: 1.2;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-editor-muted);
    margin-top: 0.75rem;
    margin-bottom: 0.125rem;
}
.section:first-of-type {
    margin-top: 0.25rem;
}
</style>
