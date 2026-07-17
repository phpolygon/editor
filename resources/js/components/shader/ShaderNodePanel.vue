<script setup lang="ts">
import { RotateCcw, Save } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import { useShaderEditorStore } from '@/stores/shaderEditor';
import { useToast } from '@/composables/useToast';

const store = useShaderEditorStore();
const { addToast } = useToast();

const nodes = [
    { type: 'uv', label: 'UV' },
    { type: 'time', label: 'Time' },
    { type: 'float', label: 'Value' },
    { type: 'color', label: 'Color' },
    { type: 'sin', label: 'Sin' },
    { type: 'scale', label: 'Scale' },
    { type: 'mix', label: 'Mix' },
];

const btn = 'px-2.5 h-8 rounded-md text-xs text-left text-editor-text hover:bg-editor-hover transition-colors';

async function save() {
    try {
        const r = await store.save();
        addToast(`Saved ${r.relativePath}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save shader', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Nodes" />
        <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-0.5">
            <button v-for="n in nodes" :key="n.type" :class="btn" @click="store.addNode(n.type)">+ {{ n.label }}</button>
        </div>
        <div class="p-2 border-t border-editor-border flex flex-col gap-1">
            <input
                v-model="store.name"
                type="text"
                placeholder="shader name"
                class="h-7 px-2 rounded-md bg-editor-input border border-editor-border text-xs
                       focus:outline-none focus:border-editor-accent"
            />
            <div class="flex gap-1">
                <Button :icon="Save" block @click="save">Save .glsl</Button>
                <Button :icon="RotateCcw" title="Reset graph" @click="store.reset()" />
            </div>
        </div>
    </div>
</template>
