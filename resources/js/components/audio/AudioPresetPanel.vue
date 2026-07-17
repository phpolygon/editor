<script setup lang="ts">
import { type Component } from 'vue';
import { Coins, Zap, Bomb, Sparkles, Swords, ArrowUpFromLine, Radio, Dices, Shuffle } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import { useAudioEditorStore } from '@/stores/audioEditor';
import type { SfxPresetName } from '@/audio/sfxr';

const store = useAudioEditorStore();

const presets: { name: SfxPresetName; label: string; icon: Component }[] = [
    { name: 'pickup', label: 'Pickup / Coin', icon: Coins },
    { name: 'laser', label: 'Laser / Shoot', icon: Zap },
    { name: 'explosion', label: 'Explosion', icon: Bomb },
    { name: 'powerup', label: 'Power Up', icon: Sparkles },
    { name: 'hit', label: 'Hit / Hurt', icon: Swords },
    { name: 'jump', label: 'Jump', icon: ArrowUpFromLine },
    { name: 'blip', label: 'Blip / Select', icon: Radio },
];

// Applying a preset and immediately auditioning it is the fast, playful loop
// this tool is about — the click is a user gesture, so audio is allowed.
async function pick(name: SfxPresetName) {
    store.applyPreset(name);
    await store.play();
}
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Presets" />
        <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-0.5">
            <button
                v-for="p in presets"
                :key="p.name"
                class="flex items-center gap-2.5 px-2.5 h-8 rounded-md text-xs text-left transition-colors"
                :class="store.currentPreset === p.name
                    ? 'bg-editor-accent text-white'
                    : 'text-editor-text hover:bg-editor-hover'"
                @click="pick(p.name)"
            >
                <component :is="p.icon" :size="15" :stroke-width="2" class="shrink-0 opacity-80" />
                {{ p.label }}
            </button>
        </div>
        <div class="p-2 border-t border-editor-border flex gap-1">
            <Button :icon="Dices" block @click="() => { store.randomize(); store.play(); }">Randomize</Button>
            <Button :icon="Shuffle" title="Mutate the current sound" @click="() => { store.mutate(); store.play(); }" />
        </div>
    </div>
</template>
