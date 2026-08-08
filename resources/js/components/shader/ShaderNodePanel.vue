<script setup lang="ts">
import { onMounted } from 'vue';
import { RotateCcw, Save, Sparkles } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import { useShaderEditorStore } from '@/stores/shaderEditor';
import { useToast } from '@/composables/useToast';

const store = useShaderEditorStore();
const { addToast } = useToast();

onMounted(() => store.refreshAssets());

async function load(name: string) {
    try {
        await store.load(name);
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to load shader', 'error');
    }
}

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

            <template v-if="store.assets.length > 0">
                <p class="mt-3 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">
                    Saved Shaders
                </p>
                <button
                    v-for="s in store.assets"
                    :key="s.name"
                    :class="[btn, 'flex items-center gap-2.5']"
                    :title="`Reopen ${s.name}`"
                    @click="load(s.name)"
                >
                    <Sparkles
                        :size="15"
                        :stroke-width="2"
                        class="shrink-0"
                        :class="s.name === store.name ? 'text-editor-accent' : 'opacity-80'"
                    />
                    <span class="truncate">{{ s.name }}</span>
                </button>
            </template>
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
