<script setup lang="ts">
import { Play, Square, Dices, Save } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Button from '@/components/ui/Button.vue';
import WaveformCanvas from './WaveformCanvas.vue';
import { useAudioEditorStore } from '@/stores/audioEditor';
import { useToast } from '@/composables/useToast';

const store = useAudioEditorStore();
const { addToast } = useToast();

const modeOptions = [
    { value: 'sfx' as const, label: 'SFX' },
    { value: 'synth' as const, label: 'Synth' },
];

async function save() {
    try {
        const r = await store.save();
        addToast(`Saved ${r.relativePath}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save audio', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Wave Synthesizer">
            <template #actions>
                <SegmentedControl
                    :model-value="store.mode"
                    :options="modeOptions"
                    @update:model-value="store.mode = $event"
                />
            </template>
        </PanelHeader>

        <div class="flex-1 flex flex-col p-4 gap-4 min-h-0">
            <div class="flex-1 min-h-[140px] rounded-lg border border-editor-border bg-editor-bg overflow-hidden">
                <WaveformCanvas :samples="store.samples" />
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <Button :icon="Play" variant="primary" size="md" @click="store.play()">Play</Button>
                <Button :icon="Square" size="md" @click="store.stopPlayback()">Stop</Button>
                <Button :icon="Dices" size="md" @click="() => { store.randomize(); store.play(); }">Randomize</Button>
                <div class="flex-1 min-w-4" />
                <input
                    v-model="store.name"
                    type="text"
                    placeholder="file name"
                    class="h-8 w-40 px-2.5 rounded-md bg-editor-input border border-editor-border text-sm
                           focus:outline-none focus:border-editor-accent focus:ring-2 focus:ring-editor-accent/40"
                />
                <Button :icon="Save" variant="primary" size="md" @click="save">Export .wav</Button>
            </div>
        </div>
    </div>
</template>
