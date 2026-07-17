<script setup lang="ts">
import { onMounted } from 'vue';
import { FileBox } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import { RotateCcw } from 'lucide-vue-next';
import { useMaterialEditorStore } from '@/stores/materialEditor';
import { MATERIAL_PRESET_NAMES, MATERIAL_PRESETS, type MaterialPresetName } from '@/material/presets';
import { useToast } from '@/composables/useToast';

const store = useMaterialEditorStore();
const { addToast } = useToast();

onMounted(() => store.refreshAssets());

function swatch(name: MaterialPresetName): string {
    const c = MATERIAL_PRESETS[name]().albedo;
    const h = (v: number) => Math.round(Math.max(0, Math.min(1, v)) * 255).toString(16).padStart(2, '0');
    return `#${h(c.r)}${h(c.g)}${h(c.b)}`;
}

async function load(id: string) {
    try {
        await store.load(id);
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to load material', 'error');
    }
}

const btn = 'flex items-center gap-2.5 px-2.5 h-8 rounded-md text-xs text-left transition-colors';
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Materials" />
        <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-0.5">
            <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Presets</p>
            <button
                v-for="name in MATERIAL_PRESET_NAMES"
                :key="name"
                :class="[btn, store.currentPreset === name ? 'bg-editor-accent text-white' : 'text-editor-text hover:bg-editor-hover']"
                @click="store.applyPreset(name)"
            >
                <span class="h-3.5 w-3.5 rounded-full border border-black/40 shrink-0" :style="{ background: swatch(name) }" />
                <span class="capitalize">{{ name }}</span>
            </button>

            <template v-if="store.assets.length > 0">
                <p class="mt-3 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Saved</p>
                <button
                    v-for="m in store.assets"
                    :key="m.id"
                    :class="[btn, 'text-editor-text hover:bg-editor-hover']"
                    @click="load(m.id)"
                >
                    <FileBox :size="14" :stroke-width="2" class="shrink-0 opacity-80" />
                    <span class="truncate">{{ m.id }}</span>
                </button>
            </template>
        </div>
        <div class="p-2 border-t border-editor-border">
            <Button :icon="RotateCcw" block @click="store.reset()">Reset</Button>
        </div>
    </div>
</template>
