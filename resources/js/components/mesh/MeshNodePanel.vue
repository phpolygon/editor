<script setup lang="ts">
import { onMounted, type Component } from 'vue';
import { Box, Circle, Cylinder, Square, Donut, Diamond, Triangle, Move3d, FlipHorizontal2, Combine, RotateCcw, FileBox } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import { useMeshEditorStore } from '@/stores/meshEditor';
import { useToast } from '@/composables/useToast';

const store = useMeshEditorStore();
const { addToast } = useToast();

onMounted(() => store.refreshAssets());

async function load(name: string) {
    try {
        await store.load(name);
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to load mesh', 'error');
    }
}

const generators: { type: string; label: string; icon: Component }[] = [
    { type: 'box', label: 'Box', icon: Box },
    { type: 'sphere', label: 'Sphere', icon: Circle },
    { type: 'cylinder', label: 'Cylinder', icon: Cylinder },
    { type: 'plane', label: 'Plane', icon: Square },
    { type: 'torus', label: 'Torus', icon: Donut },
    { type: 'octahedron', label: 'Octahedron', icon: Diamond },
    { type: 'wedge', label: 'Wedge', icon: Triangle },
];

const operators: { type: string; label: string; icon: Component }[] = [
    { type: 'transform', label: 'Transform', icon: Move3d },
    { type: 'mirror', label: 'Mirror', icon: FlipHorizontal2 },
    { type: 'combine', label: 'Combine', icon: Combine },
];

const btn = 'flex items-center gap-2.5 px-2.5 h-8 rounded-md text-xs text-left text-editor-text hover:bg-editor-hover transition-colors';
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Nodes" />
        <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-0.5">
            <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Generators</p>
            <button v-for="g in generators" :key="g.type" :class="btn" @click="store.addNodeOfType(g.type)">
                <component :is="g.icon" :size="15" :stroke-width="2" class="shrink-0 opacity-80" />
                {{ g.label }}
            </button>
            <p class="mt-3 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Operators</p>
            <button v-for="o in operators" :key="o.type" :class="btn" @click="store.addNodeOfType(o.type)">
                <component :is="o.icon" :size="15" :stroke-width="2" class="shrink-0 opacity-80" />
                {{ o.label }}
            </button>

            <template v-if="store.assets.length > 0">
                <p class="mt-3 mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Saved Meshes</p>
                <button
                    v-for="m in store.assets"
                    :key="m.name"
                    :class="btn"
                    :title="`Load ${m.name}`"
                    @click="load(m.name)"
                >
                    <FileBox :size="15" :stroke-width="2" class="shrink-0 opacity-80" />
                    <span class="truncate">{{ m.name }}</span>
                </button>
            </template>
        </div>
        <div class="p-2 border-t border-editor-border">
            <Button :icon="RotateCcw" block @click="store.reset()">Reset to box</Button>
        </div>
    </div>
</template>
