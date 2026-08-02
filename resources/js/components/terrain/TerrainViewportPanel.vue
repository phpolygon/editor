<script setup lang="ts">
import { Mountain, Layers, Trees } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import TerrainSculptViewport from './TerrainSculptViewport.vue';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import { useProjectStore } from '@/stores/project';

/**
 * Centre panel: the sculpt viewport with the tool switch in its header.
 *
 * Terrain assets are written into the project's `assets/` directory, so the
 * viewport is gated on an open project rather than failing at the first save.
 */
const store = useTerrainEditorStore();
const project = useProjectStore();

const MODES = [
    { value: 'sculpt' as const, label: 'Sculpt', icon: Mountain },
    { value: 'paint' as const, label: 'Paint', icon: Layers },
    { value: 'scatter' as const, label: 'Scatter', icon: Trees },
];
</script>

<template>
    <div class="flex flex-col h-full min-h-0">
        <PanelHeader :title="store.name || 'Terrain'">
            <template #actions>
                <SegmentedControl v-model="store.mode" :options="MODES" />
            </template>
        </PanelHeader>

        <div class="flex-1 min-h-0 relative">
            <EmptyState
                v-if="!project.opened"
                :icon="Mountain"
                title="No project open"
                hint="Open a project to sculpt terrain — terrain assets are saved into its assets directory."
            />
            <TerrainSculptViewport v-else />
        </div>

        <p
            v-if="store.error"
            class="px-3 py-1.5 border-t border-editor-border text-[11px] text-editor-danger truncate"
        >
            {{ store.error }}
        </p>
    </div>
</template>
