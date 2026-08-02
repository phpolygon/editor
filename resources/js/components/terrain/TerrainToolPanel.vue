<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
    Mountain,
    Brush,
    Layers,
    Trees,
    Plus,
    Wand2,
    RotateCcw,
    FileTerminal,
    Trash2,
    Upload,
    Download,
    Undo2,
    Redo2,
} from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import Slider from '@/components/ui/Slider.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import { useToast } from '@/composables/useToast';
import { useDialog } from '@/composables/useDialog';
import { BRUSHES } from '@/terrain/brushes';
import { GENERATORS } from '@/terrain/generators';
import { layerTintCss } from '@/terrain/layerTints';

/**
 * Left panel: what the pointer does (tool + brush) and where terrain shape
 * comes from (generators, heightmap import), plus the saved-asset list.
 */
const store = useTerrainEditorStore();
const { addToast } = useToast();
const dialog = useDialog();
const fileInput = ref<HTMLInputElement | null>(null);

onMounted(() => store.refreshAssets());

const MODES = [
    { value: 'sculpt', label: 'Sculpt', icon: Mountain },
    { value: 'paint', label: 'Paint', icon: Layers },
    { value: 'scatter', label: 'Scatter', icon: Trees },
];

async function load(name: string) {
    if (store.dirty) {
        const ok = await dialog.confirm({
            title: 'Discard unsaved changes?',
            message: `“${store.name}” has unsaved edits that will be lost.`,
            confirmLabel: 'Discard',
            danger: true,
        });
        if (!ok) return;
    }
    await store.load(name);
    if (store.error) addToast(store.error, 'error');
}

async function remove(name: string) {
    const ok = await dialog.confirm({
        title: `Delete “${name}”?`,
        message: 'This permanently removes the saved terrain asset.',
        confirmLabel: 'Delete',
        danger: true,
    });
    if (!ok) return;
    await store.remove(name);
    if (store.error) addToast(store.error, 'error');
    else addToast('Terrain deleted', 'success');
}

function generate() {
    store.generate();
    addToast('Terrain generated', 'success');
}

/**
 * Import a greyscale heightmap image.
 *
 * Decoded through a canvas because that is the only way to get at pixel data
 * for an arbitrary image format the browser can already decode.
 */
async function onImportImage(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = ''; // allow re-importing the same file
    if (!file) return;

    try {
        const bitmap = await createImageBitmap(file);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Canvas 2D context unavailable');

        context.drawImage(bitmap, 0, 0);
        store.importHeightmapImage(context.getImageData(0, 0, bitmap.width, bitmap.height));
        bitmap.close();
        addToast(`Imported ${file.name}`, 'success');
    } catch (e: unknown) {
        addToast(e instanceof Error ? e.message : 'Heightmap import failed', 'error');
    }
}

function exportImage() {
    const image = store.exportHeightmapImage();
    const canvas = document.createElement('canvas');
    canvas.width = image.width;
    canvas.height = image.height;
    const context = canvas.getContext('2d');
    if (!context) {
        addToast('Canvas 2D context unavailable', 'error');
        return;
    }

    context.putImageData(image, 0, 0);
    canvas.toBlob((blob) => {
        if (!blob) {
            addToast('Heightmap export failed', 'error');
            return;
        }
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${store.name}-heightmap.png`;
        link.click();
        URL.revokeObjectURL(url);
    }, 'image/png');
}

/** The brush sliders are shared, so the heading says what they currently drive. */
const brushSettingsTitle = computed(() => {
    switch (store.mode) {
        case 'paint':
            return 'Paint brush';
        case 'scatter':
            return 'Density brush';
        default:
            return 'Brush settings';
    }
});

function fillActiveLayer() {
    store.applyLayerRules(store.activeLayer);
    addToast('Layer filled from its height and slope rules', 'success');
}

const btn =
    'flex items-center gap-2.5 px-2.5 h-8 rounded-md text-xs text-left text-editor-text hover:bg-editor-hover transition-colors';
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Terrain" />

        <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-3">
            <div>
                <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Tool</p>
                <SegmentedControl
                    :model-value="store.mode"
                    :options="MODES"
                    @update:model-value="store.mode = $event as typeof store.mode"
                />
            </div>

            <div v-if="store.mode === 'sculpt'">
                <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Brush</p>
                <button
                    v-for="b in BRUSHES"
                    :key="b.type"
                    :class="[
                        btn,
                        'w-full',
                        b.type === store.brush.type ? 'bg-editor-hover ring-1 ring-inset ring-editor-accent/40' : '',
                    ]"
                    :title="b.hint"
                    @click="store.brush.type = b.type"
                >
                    <Brush :size="15" :stroke-width="2" class="shrink-0 opacity-80" />
                    {{ b.label }}
                </button>
                <p class="px-2.5 pt-1 text-[11px] leading-snug text-editor-muted">
                    {{ BRUSHES.find((b) => b.type === store.brush.type)?.hint }}
                </p>
            </div>

            <!-- Paint: pick the layer the brush writes into. The layer's full
                 settings live in the Properties panel; what a painter needs here
                 is which one is active. -->
            <div v-else-if="store.mode === 'paint'">
                <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">
                    Layer to paint
                </p>

                <EmptyState
                    v-if="store.layers.length === 0"
                    :icon="Layers"
                    title="No texture layers"
                    hint="Painting needs at least one layer to write coverage into."
                    compact
                >
                    <template #actions>
                        <Button :icon="Plus" @click="store.addLayer()">Add a layer</Button>
                    </template>
                </EmptyState>

                <template v-else>
                    <button
                        v-for="(layer, index) in store.layers"
                        :key="layer.id"
                        :class="[
                            btn,
                            'w-full',
                            index === store.activeLayer
                                ? 'bg-editor-hover ring-1 ring-inset ring-editor-accent/40'
                                : '',
                        ]"
                        @click="store.activeLayer = index"
                    >
                        <span
                            class="shrink-0 h-3.5 w-3.5 rounded border border-black/30"
                            :style="{ background: layerTintCss(index) }"
                        />
                        <span class="truncate">{{ layer.name }}</span>
                    </button>
                    <div class="flex gap-1 pt-1.5">
                        <Button :icon="Plus" class="flex-1" @click="store.addLayer()">Add</Button>
                        <Button :icon="Wand2" class="flex-1" @click="fillActiveLayer">From rules</Button>
                    </div>
                    <p class="px-2.5 pt-1 text-[11px] leading-snug text-editor-muted">
                        Drag to paint “{{ store.layers[store.activeLayer]?.name }}”, hold Alt to erase
                        it. Other layers give way so coverage always totals 100%.
                    </p>
                </template>
            </div>

            <!-- Scatter: pick the set whose density the brush paints. -->
            <div v-else-if="store.mode === 'scatter'">
                <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">
                    Set to scatter
                </p>

                <EmptyState
                    v-if="store.scatterSets.length === 0"
                    :icon="Trees"
                    title="No scatter sets"
                    hint="Scattering needs a set to hold the mesh, seed and density you paint."
                    compact
                >
                    <template #actions>
                        <Button :icon="Plus" @click="store.addScatterSet()">Add a set</Button>
                    </template>
                </EmptyState>

                <template v-else>
                    <button
                        v-for="(set, index) in store.scatterSets"
                        :key="set.id"
                        :class="[
                            btn,
                            'w-full',
                            index === store.activeScatter
                                ? 'bg-editor-hover ring-1 ring-inset ring-editor-accent/40'
                                : '',
                        ]"
                        @click="store.activeScatter = index"
                    >
                        <Trees :size="15" :stroke-width="2" class="shrink-0 opacity-80" />
                        <span class="truncate">{{ set.name }}</span>
                        <span v-if="!set.meshId" class="ml-auto text-[10px] text-editor-muted shrink-0">
                            no mesh
                        </span>
                    </button>
                    <Button :icon="Plus" block class="mt-1.5" @click="store.addScatterSet()">Add set</Button>
                    <p class="px-2.5 pt-1 text-[11px] leading-snug text-editor-muted">
                        Drag to paint density for “{{ store.scatterSets[store.activeScatter]?.name }}”,
                        hold Alt to thin it out. Instances follow the set's slope and height rules.
                    </p>
                    <p
                        v-if="!store.scatterSets[store.activeScatter]?.meshId"
                        class="px-2.5 pt-1 text-[11px] leading-snug text-editor-warning"
                    >
                        This set has no mesh yet, so it renders nothing in game. Set one in Properties.
                    </p>
                </template>
            </div>

            <div class="flex flex-col gap-1.5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-editor-muted">
                    {{ brushSettingsTitle }}
                </p>
                <Slider
                    v-model="store.brush.radius"
                    label="Radius"
                    :min="1"
                    :max="Math.max(10, store.heightmap.sizeX / 2)"
                    :step="0.5"
                    :format="(v) => `${v.toFixed(0)} m`"
                />
                <Slider v-model="store.brush.strength" label="Strength" :min="0.05" :max="1" :step="0.05" />
                <Slider v-model="store.brush.falloff" label="Falloff" :min="0" :max="1" :step="0.05" />
                <Slider
                    v-if="store.mode === 'sculpt' && store.brush.type === 'flatten'"
                    :model-value="store.brush.targetHeight ?? store.heightmap.minHeight"
                    label="Target height"
                    :min="store.heightmap.minHeight"
                    :max="store.heightmap.maxHeight"
                    :step="0.5"
                    :format="(v) => `${v.toFixed(1)} m`"
                    @update:model-value="store.brush.targetHeight = $event"
                />
            </div>

            <!-- Generating replaces the whole heightmap, so it belongs with the
                 sculpt tools rather than sitting under a paint brush. -->
            <div v-if="store.mode === 'sculpt'" class="flex flex-col gap-1.5">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-editor-muted">Generate</p>
                <select
                    v-model="store.generator.type"
                    class="h-8 px-2 rounded-md bg-editor-elevated border border-editor-border text-xs text-editor-text"
                >
                    <option v-for="g in GENERATORS" :key="g.type" :value="g.type">{{ g.label }}</option>
                </select>
                <p class="text-[11px] leading-snug text-editor-muted">
                    {{ GENERATORS.find((g) => g.type === store.generator.type)?.hint }}
                </p>
                <Slider
                    v-model="store.generator.scale"
                    label="Feature size"
                    :min="5"
                    :max="400"
                    :step="5"
                    :format="(v) => `${v.toFixed(0)} m`"
                />
                <Slider v-model="store.generator.octaves" label="Detail" :min="1" :max="8" :step="1" :format="(v) => v.toFixed(0)" />
                <Slider v-model="store.generator.persistence" label="Roughness" :min="0.1" :max="0.9" :step="0.05" />
                <Slider v-model="store.generator.amplitude" label="Amplitude" :min="0" :max="1" :step="0.05" />
                <Slider v-model="store.generator.baseLevel" label="Base level" :min="0" :max="1" :step="0.05" />
                <Slider
                    v-if="store.generator.type === 'terraced'"
                    v-model="store.generator.terraces"
                    label="Terraces"
                    :min="2"
                    :max="20"
                    :step="1"
                    :format="(v) => v.toFixed(0)"
                />
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-28 shrink-0 text-editor-muted">Seed</span>
                    <input
                        v-model.number="store.generator.seed"
                        type="number"
                        class="flex-1 h-7 px-2 rounded bg-editor-elevated border border-editor-border text-xs text-editor-text"
                    />
                </label>
                <Button :icon="Mountain" block @click="generate">Generate terrain</Button>
            </div>

            <div>
                <p class="mb-0.5 text-[10px] font-semibold uppercase tracking-wider text-editor-muted">
                    Saved terrains
                </p>
                <p v-if="store.assets.length === 0" class="px-2.5 py-1 text-[11px] text-editor-muted">
                    No saved terrains yet. Sculpt one and hit Save.
                </p>
                <div
                    v-for="t in store.assets"
                    :key="t.name"
                    class="group flex items-center rounded-md hover:bg-editor-hover transition-colors"
                    :class="t.name === store.loadedAssetName ? 'bg-editor-hover ring-1 ring-inset ring-editor-accent/40' : ''"
                >
                    <button :class="[btn, 'flex-1 min-w-0 hover:bg-transparent']" :title="`Load ${t.name}`" @click="load(t.name)">
                        <FileTerminal
                            :size="15"
                            :stroke-width="2"
                            class="shrink-0"
                            :class="t.name === store.loadedAssetName ? 'text-editor-accent opacity-100' : 'opacity-80'"
                        />
                        <span class="truncate">{{ t.name }}</span>
                    </button>
                    <button
                        class="p-1 mr-1 rounded text-editor-muted hover:text-editor-danger hover:bg-editor-elevated opacity-0 group-hover:opacity-100 transition-opacity"
                        title="Delete"
                        @click.stop="remove(t.name)"
                    >
                        <Trash2 :size="13" :stroke-width="2" />
                    </button>
                </div>
            </div>
        </div>

        <div class="p-2 border-t border-editor-border flex flex-col gap-1">
            <div class="flex gap-1">
                <Button :icon="Undo2" :disabled="!store.canUndo" class="flex-1" @click="store.undo()">Undo</Button>
                <Button :icon="Redo2" :disabled="!store.canRedo" class="flex-1" @click="store.redo()">Redo</Button>
            </div>
            <Button :icon="Upload" block @click="fileInput?.click()">Import heightmap…</Button>
            <Button :icon="Download" block @click="exportImage">Export heightmap</Button>
            <Button :icon="RotateCcw" block @click="store.reset()">Reset terrain</Button>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onImportImage" />
        </div>
    </div>
</template>
