<script setup lang="ts">
import { computed } from 'vue';
import { Clapperboard, Grid3x3, Magnet, Mountain, Radio } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import IconButton from '@/components/ui/IconButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Select from '@/components/ui/Select.vue';
import Slider from '@/components/ui/Slider.vue';
import SceneViewport3D from '@/components/scene/SceneViewport3D.vue';
import SceneViewport2D from '@/components/scene/SceneViewport2D.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useEditorStore } from '@/stores/editor';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import {
    useViewportStore,
    TRANSLATE_STEPS,
    ROTATE_STEPS,
    SCALE_STEPS,
    type GizmoSpace,
} from '@/stores/viewport';
import { BRUSHES } from '@/terrain/brushes';
import { sculptability } from '@/terrain/sceneSculpt';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
const terrainStore = useTerrainEditorStore();
const viewportStore = useViewportStore();
const editorStore = useEditorStore();

/** The viewport is showing the running game's world, not the authored scene. */
const live = computed(
    () => editorStore.playing && editorStore.liveEntities !== null && sceneStore.mode === '3d',
);

/**
 * The game is running but never mirrored its world. Worth saying out loud: the
 * viewport still shows the authored scene, which is easy to mistake for a live
 * view that simply is not moving.
 */
const liveUnavailable = computed(
    () => editorStore.playing && !editorStore.liveWorldAvailable,
);

const translateOptions = TRANSLATE_STEPS.map((v) => ({ value: v, label: `${v} m` }));
const rotateOptions = ROTATE_STEPS.map((v) => ({ value: v, label: `${v}°` }));
const scaleOptions = SCALE_STEPS.map((v) => ({ value: v, label: `${v}×` }));

const spaceOptions: ReadonlyArray<{ value: GizmoSpace; label: string; title: string }> = [
    { value: 'world', label: 'World', title: 'Gizmo follows the world axes (X)' },
    { value: 'local', label: 'Local', title: "Gizmo follows the entity's own axes (X)" },
];

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

// Snapping only has a gizmo to act on in the 3D viewport, and the bar would be
// dead chrome while sculpting or watching the live world (the gizmo is detached
// in both).
const showTransformBar = computed(
    () => sceneStore.mode === '3d' && !sculpting.value && !live.value,
);

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
                    v-if="showTransformBar"
                    :icon="Grid3x3"
                    :active="viewportStore.showGrid"
                    label="Show the ground grid"
                    @click="viewportStore.showGrid = !viewportStore.showGrid"
                />
                <IconButton
                    v-if="showTransformBar"
                    :icon="Magnet"
                    :active="viewportStore.snapEnabled"
                    label="Snap transforms to fixed increments (hold ctrl to invert)"
                    @click="viewportStore.toggleSnap()"
                />
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

        <!-- Gizmo settings sit inline, mirroring the sculpt brush bar below. -->
        <div
            v-if="showTransformBar"
            class="flex items-center gap-3 px-3 py-1.5 border-b border-editor-border bg-editor-elevated"
        >
            <SegmentedControl v-model="viewportStore.gizmoSpace" :options="spaceOptions" />

            <template v-if="viewportStore.snapEnabled">
                <label class="flex items-center gap-1.5 text-[11px] text-editor-muted">
                    Move
                    <Select v-model="viewportStore.translateStep" :options="translateOptions" />
                </label>
                <label class="flex items-center gap-1.5 text-[11px] text-editor-muted">
                    Rotate
                    <Select v-model="viewportStore.rotateStep" :options="rotateOptions" />
                </label>
                <label class="flex items-center gap-1.5 text-[11px] text-editor-muted">
                    Scale
                    <Select v-model="viewportStore.scaleStep" :options="scaleOptions" />
                </label>
            </template>

            <span class="ml-auto text-[11px] text-editor-muted whitespace-nowrap">
                {{ viewportStore.snapEnabled ? 'Ctrl moves freely' : 'Ctrl snaps' }} · W/E/R · X space · F focus
            </span>
        </div>

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

        <!-- The game runs but never mirrored its world, so the viewport below is
             still the authored scene — say so rather than letting it read as a
             live view that is not moving. -->
        <div
            v-if="liveUnavailable"
            class="px-3 py-1.5 border-b border-editor-border bg-editor-elevated text-[11px] text-editor-muted"
        >
            Showing the authored scene — this game does not mirror its world to the editor. Call
            <code class="text-editor-text">$engine-&gt;enableEditorSync(getenv('PHPOLYGON_EDITOR_SYNC'))</code>
            in its boot class to see what is running.
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

            <!-- Play mode is visually unmistakable: what is on screen belongs to
                 the running game, and edits do not reach it. -->
            <div
                v-if="live"
                class="absolute inset-0 pointer-events-none ring-2 ring-inset ring-editor-accent"
            >
                <div
                    class="absolute top-2 left-1/2 -translate-x-1/2 flex items-center gap-2 px-2.5 py-1
                           rounded-full bg-editor-accent/90 text-white text-[11px] font-medium shadow-sm"
                >
                    <Radio :size="12" />
                    Live — running game (read-only)
                    <span v-if="editorStore.liveChildrenOmitted > 0" class="opacity-80">
                        · {{ editorStore.liveChildrenOmitted }} child entities hidden
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
