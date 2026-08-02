<script setup lang="ts">
import { computed } from 'vue';
import { Clapperboard, Mountain } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import IconButton from '@/components/ui/IconButton.vue';
import Slider from '@/components/ui/Slider.vue';
import SceneViewport3D from '@/components/scene/SceneViewport3D.vue';
import SceneViewport2D from '@/components/scene/SceneViewport2D.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import { BRUSHES } from '@/terrain/brushes';
import { sculptability } from '@/terrain/sceneSculpt';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
const terrainStore = useTerrainEditorStore();

/**
 * In-scene sculpting only applies to a selected entity carrying a Terrain
 * component, so the toggle reports *why* it is unavailable instead of silently
 * doing nothing when clicked.
 */
const target = computed(() => {
    const selected = selectionStore.selectedEntity;
    if (!selected) {
        return { ok: false as const, reason: 'Select a terrain entity to sculpt it here.' };
    }
    return sculptability(sceneStore.viewEntities.find((e) => e.name === selected));
});

const sculptAvailable = computed(() => target.value.ok);
const sculpting = computed(() => terrainStore.sceneSculptEnabled && sculptAvailable.value);

function toggleSculpt() {
    terrainStore.sceneSculptEnabled = !terrainStore.sceneSculptEnabled;
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <!-- Play lives in the toolbar now; the viewport header just names the scene. -->
        <PanelHeader :title="sceneStore.name ? `Scene: ${sceneStore.name}` : 'Scene View'">
            <template #actions>
                <IconButton
                    v-if="sceneStore.mode === '3d'"
                    :icon="Mountain"
                    :active="sculpting"
                    :disabled="!sculptAvailable"
                    :label="target.ok ? 'Sculpt this terrain in place' : target.reason"
                    @click="toggleSculpt"
                />
            </template>
        </PanelHeader>

        <!-- Brush controls sit inline while sculpting so the terrain stays
             visible, rather than being covered by a floating tool panel. -->
        <div
            v-if="sculpting"
            class="flex items-center gap-3 px-3 py-1.5 border-b border-editor-border bg-editor-elevated"
        >
            <select
                v-model="terrainStore.brush.type"
                class="h-6 px-1.5 rounded bg-editor-bg border border-editor-border text-xs text-editor-text"
            >
                <option v-for="b in BRUSHES" :key="b.type" :value="b.type">{{ b.label }}</option>
            </select>
            <Slider
                v-model="terrainStore.brush.radius"
                label="Radius"
                :min="1"
                :max="200"
                :step="1"
                :format="(v) => `${v.toFixed(0)} m`"
                class="flex-1 max-w-64"
            />
            <Slider
                v-model="terrainStore.brush.strength"
                label="Strength"
                :min="0.05"
                :max="1"
                :step="0.05"
                class="flex-1 max-w-56"
            />
            <span class="text-[11px] text-editor-muted whitespace-nowrap">Alt inverts</span>
        </div>

        <div class="flex-1 relative">
            <EmptyState
                v-if="!sceneStore.name"
                :icon="Clapperboard"
                title="No scene loaded"
                hint="Pick a scene from the toolbar dropdown, or create a new one with “New Scene”."
                class="absolute inset-0"
            />

            <SceneViewport3D v-else-if="sceneStore.mode === '3d'" class="absolute inset-0" />
            <SceneViewport2D v-else class="absolute inset-0" />
        </div>
    </div>
</template>
