<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Slider from '@/components/ui/Slider.vue';
import ColorInput from '@/components/ui/ColorInput.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import MaterialGraphCanvas from './MaterialGraphCanvas.vue';
import { useMaterialEditorStore } from '@/stores/materialEditor';
import { addMatNode, createMatNode, uniqueMatNodeId } from '@/material/matGraph';

const store = useMaterialEditorStore();

const modeOptions = [
    { value: 'props' as const, label: 'Props' },
    { value: 'graph' as const, label: 'Graph' },
];

const nodeButtons = [
    { type: 'colorConst', label: 'Color' },
    { type: 'floatConst', label: 'Value' },
    { type: 'mix', label: 'Mix' },
    { type: 'multiply', label: 'Multiply' },
];

function addNode(type: string) {
    store.setGraph(addMatNode(store.graph, createMatNode(type, uniqueMatNodeId(store.graph, type))));
}
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader :title="store.graphMode ? 'Material Graph' : 'Properties'">
            <template #actions>
                <SegmentedControl
                    :model-value="store.graphMode ? 'graph' : 'props'"
                    :options="modeOptions"
                    @update:model-value="store.setGraphMode($event === 'graph')"
                />
            </template>
        </PanelHeader>

        <!-- Property sliders -->
        <div v-if="!store.graphMode" class="flex-1 overflow-y-auto p-3 flex flex-col gap-1.5">
            <p class="section">Base</p>
            <ColorInput v-model="store.material.albedo" label="Albedo" />
            <Slider v-model="store.material.roughness" label="Roughness" />
            <Slider v-model="store.material.metallic" label="Metallic" />
            <Slider v-model="store.material.alpha" label="Alpha" />

            <p class="section">Emission</p>
            <ColorInput v-model="store.material.emission" label="Emission" />

            <p class="section">Clearcoat</p>
            <Slider v-model="store.material.clearcoat" label="Clearcoat" />
            <Slider v-model="store.material.clearcoatRoughness" label="CC roughness" />

            <p class="section">Surface</p>
            <Slider v-model="store.material.normalIntensity" label="Normal int." :min="0" :max="2" />
            <label class="flex items-center gap-2 text-xs mt-1">
                <input
                    type="checkbox"
                    :checked="store.material.useEnvironmentMap"
                    class="accent-editor-accent"
                    @change="store.material.useEnvironmentMap = ($event.target as HTMLInputElement).checked"
                />
                <span class="text-editor-muted">Use environment map</span>
            </label>
        </div>

        <!-- Node graph -->
        <template v-else>
            <div class="p-2 border-b border-editor-border flex gap-1 flex-wrap">
                <button
                    v-for="b in nodeButtons"
                    :key="b.type"
                    class="px-2 h-6 rounded text-[11px] bg-editor-elevated border border-editor-border text-editor-text hover:bg-editor-active"
                    @click="addNode(b.type)"
                >
                    + {{ b.label }}
                </button>
            </div>
            <MaterialGraphCanvas
                class="flex-1 min-h-0"
                :model-value="store.graph"
                @update:model-value="store.setGraph"
            />
        </template>
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
