<script setup lang="ts">
import { computed } from 'vue';
import { Plus, Trash2, Wand2, Save, Box, Boxes, MapPin, Image } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import Slider from '@/components/ui/Slider.vue';
import { useTerrainEditorStore } from '@/stores/terrainEditor';
import { useToast } from '@/composables/useToast';
import { RESOLUTIONS } from '@/terrain/heightmap';
import { countScatterInstances } from '@/terrain/scatter';

/**
 * Right panel: the terrain's own properties, its texture layers and scatter
 * sets, and the actions that get it out of the editor (save, bake, place).
 */
const store = useTerrainEditorStore();
const { addToast } = useToast();

const activeScatterSet = computed(() => store.scatterSets[store.activeScatter] ?? null);

const scatterCount = computed(() => {
    const set = activeScatterSet.value;
    // Recomputes with the terrain revision so the count tracks density painting.
    void store.revision;
    return set ? countScatterInstances(set, store.heightmap, 20000) : 0;
});

const chunkCount = computed(() => {
    const quadsX = store.gridWidth - 1;
    const quadsZ = store.gridDepth - 1;
    const size = Math.max(1, store.chunkSize);
    return Math.ceil(quadsX / size) * Math.ceil(quadsZ / size);
});

async function save() {
    const result = await store.save();
    if (result) addToast(`Saved ${result.name}`, 'success');
    else if (store.error) addToast(store.error, 'error');
}

async function bake(chunked: boolean) {
    const result = await store.bake({ chunked, save: true });
    if (result) {
        addToast(`Baked ${result.meshes.length} mesh${result.meshes.length === 1 ? '' : 'es'}`, 'success');
    } else if (store.error) {
        addToast(store.error, 'error');
    }
}

async function place() {
    const result = await store.placeInScene({ collider: true });
    if (result) {
        const scatter = result.scatterSets > 0 ? ` with ${result.scatterSets} scatter set(s)` : '';
        addToast(`Placed “${result.created}” in the scene${scatter}`, 'success');
    } else if (store.error) {
        addToast(store.error, 'error');
    }
}

async function bakeLayers() {
    const result = await store.bakeLayers();
    if (result) addToast(`Baked layers into ${result.materialId}`, 'success');
    else if (store.error) addToast(store.error, 'error');
}

function applyRules(index: number) {
    store.applyLayerRules(index);
    addToast('Layer filled from its height and slope rules', 'success');
}

const sectionTitle = 'text-[10px] font-semibold uppercase tracking-wider text-editor-muted';
const field = 'h-7 px-2 rounded bg-editor-elevated border border-editor-border text-xs text-editor-text';
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Properties" />

        <div class="flex-1 overflow-y-auto p-2 flex flex-col gap-4">
            <!-- Terrain -->
            <div class="flex flex-col gap-1.5">
                <p :class="sectionTitle">Terrain</p>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 text-editor-muted">Name</span>
                    <input v-model="store.name" type="text" :class="[field, 'flex-1']" />
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 text-editor-muted">Resolution</span>
                    <select
                        :value="store.gridWidth"
                        :class="[field, 'flex-1']"
                        @change="store.setResolution(Number(($event.target as HTMLSelectElement).value), Number(($event.target as HTMLSelectElement).value))"
                    >
                        <option v-for="r in RESOLUTIONS" :key="r" :value="r">{{ r }} × {{ r }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 text-editor-muted">Size X</span>
                    <input
                        :value="store.heightmap.sizeX"
                        type="number"
                        min="1"
                        :class="[field, 'flex-1']"
                        @change="store.setWorldSize(Number(($event.target as HTMLInputElement).value), store.heightmap.sizeZ)"
                    />
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 text-editor-muted">Size Z</span>
                    <input
                        :value="store.heightmap.sizeZ"
                        type="number"
                        min="1"
                        :class="[field, 'flex-1']"
                        @change="store.setWorldSize(store.heightmap.sizeX, Number(($event.target as HTMLInputElement).value))"
                    />
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 text-editor-muted">Min height</span>
                    <input
                        :value="store.heightmap.minHeight"
                        type="number"
                        :class="[field, 'flex-1']"
                        @change="store.setHeightRange(Number(($event.target as HTMLInputElement).value), store.heightmap.maxHeight)"
                    />
                </label>
                <label class="flex items-center gap-2 text-xs">
                    <span class="w-24 shrink-0 text-editor-muted">Max height</span>
                    <input
                        :value="store.heightmap.maxHeight"
                        type="number"
                        :class="[field, 'flex-1']"
                        @change="store.setHeightRange(store.heightmap.minHeight, Number(($event.target as HTMLInputElement).value))"
                    />
                </label>
                <Slider
                    v-model="store.chunkSize"
                    label="Chunk size"
                    :min="8"
                    :max="128"
                    :step="8"
                    :format="(v) => `${v.toFixed(0)} quads`"
                />
                <p class="text-[11px] text-editor-muted">
                    {{ store.vertexCount.toLocaleString() }} vertices ·
                    {{ store.triangleCount.toLocaleString() }} triangles · {{ chunkCount }} chunks
                </p>
            </div>

            <!-- Layers -->
            <div class="flex flex-col gap-1.5">
                <div class="flex items-center justify-between">
                    <p :class="sectionTitle">Texture layers</p>
                    <button
                        class="p-1 rounded text-editor-muted hover:text-editor-text hover:bg-editor-hover"
                        title="Add layer"
                        @click="store.addLayer()"
                    >
                        <Plus :size="14" :stroke-width="2" />
                    </button>
                </div>
                <p v-if="store.layers.length === 0" class="text-[11px] text-editor-muted">
                    No layers. Add one to paint materials onto the terrain.
                </p>
                <template v-else>
                    <Button :icon="Image" block :disabled="store.busy" @click="bakeLayers">
                        Bake layers to texture
                    </Button>
                    <p class="text-[11px] leading-snug text-editor-muted">
                        Blends the painted layers into one albedo texture and assigns it as the
                        terrain material — this is what the running game renders. Layers do not
                        tile with close-up detail; only colour is baked.
                    </p>
                </template>

                <div
                    v-for="(layer, index) in store.layers"
                    :key="layer.id"
                    class="rounded-md border p-2 flex flex-col gap-1.5 transition-colors"
                    :class="
                        index === store.activeLayer
                            ? 'border-editor-accent/50 bg-editor-hover'
                            : 'border-editor-border'
                    "
                    @click="store.activeLayer = index"
                >
                    <div class="flex items-center gap-1">
                        <input v-model="layer.name" type="text" :class="[field, 'flex-1 min-w-0']" />
                        <button
                            class="p-1 rounded text-editor-muted hover:text-editor-text hover:bg-editor-elevated"
                            title="Fill from height and slope rules"
                            @click.stop="applyRules(index)"
                        >
                            <Wand2 :size="13" :stroke-width="2" />
                        </button>
                        <button
                            class="p-1 rounded text-editor-muted hover:text-editor-danger hover:bg-editor-elevated"
                            title="Remove layer"
                            @click.stop="store.removeLayer(index)"
                        >
                            <Trash2 :size="13" :stroke-width="2" />
                        </button>
                    </div>
                    <label class="flex items-center gap-2 text-xs">
                        <span class="w-20 shrink-0 text-editor-muted">Material</span>
                        <input v-model="layer.materialId" type="text" placeholder="material id" :class="[field, 'flex-1 min-w-0']" />
                    </label>
                    <Slider v-model="layer.uvScale" label="UV scale" :min="1" :max="64" :step="1" :format="(v) => v.toFixed(0)" />
                    <Slider v-model="layer.minHeight" label="Min height" :min="0" :max="1" :step="0.01" />
                    <Slider v-model="layer.maxHeight" label="Max height" :min="0" :max="1" :step="0.01" />
                    <Slider v-model="layer.minSlope" label="Min slope" :min="0" :max="90" :step="1" :format="(v) => `${v.toFixed(0)}°`" />
                    <Slider v-model="layer.maxSlope" label="Max slope" :min="0" :max="90" :step="1" :format="(v) => `${v.toFixed(0)}°`" />
                </div>
            </div>

            <!-- Scatter -->
            <div class="flex flex-col gap-1.5">
                <div class="flex items-center justify-between">
                    <p :class="sectionTitle">Scatter</p>
                    <button
                        class="p-1 rounded text-editor-muted hover:text-editor-text hover:bg-editor-hover"
                        title="Add scatter set"
                        @click="store.addScatterSet()"
                    >
                        <Plus :size="14" :stroke-width="2" />
                    </button>
                </div>
                <p v-if="store.scatterSets.length === 0" class="text-[11px] text-editor-muted">
                    No scatter sets. Add one to paint trees, grass or rocks.
                </p>

                <div
                    v-for="(set, index) in store.scatterSets"
                    :key="set.id"
                    class="rounded-md border p-2 flex flex-col gap-1.5 transition-colors"
                    :class="
                        index === store.activeScatter
                            ? 'border-editor-accent/50 bg-editor-hover'
                            : 'border-editor-border'
                    "
                    @click="store.activeScatter = index"
                >
                    <div class="flex items-center gap-1">
                        <input v-model="set.name" type="text" :class="[field, 'flex-1 min-w-0']" />
                        <button
                            class="p-1 rounded text-editor-muted hover:text-editor-danger hover:bg-editor-elevated"
                            title="Remove scatter set"
                            @click.stop="store.removeScatterSet(index)"
                        >
                            <Trash2 :size="13" :stroke-width="2" />
                        </button>
                    </div>
                    <label class="flex items-center gap-2 text-xs">
                        <span class="w-20 shrink-0 text-editor-muted">Mesh</span>
                        <input v-model="set.meshId" type="text" placeholder="mesh id" :class="[field, 'flex-1 min-w-0']" />
                    </label>
                    <label class="flex items-center gap-2 text-xs">
                        <span class="w-20 shrink-0 text-editor-muted">Seed</span>
                        <input v-model.number="set.seed" type="number" :class="[field, 'flex-1 min-w-0']" />
                    </label>
                    <Slider v-model="set.densityPerUnit" label="Density" :min="0" :max="1" :step="0.01" />
                    <Slider v-model="set.minSlope" label="Min slope" :min="0" :max="90" :step="1" :format="(v) => `${v.toFixed(0)}°`" />
                    <Slider v-model="set.maxSlope" label="Max slope" :min="0" :max="90" :step="1" :format="(v) => `${v.toFixed(0)}°`" />
                    <Slider v-model="set.minScale" label="Min scale" :min="0.1" :max="3" :step="0.1" />
                    <Slider v-model="set.maxScale" label="Max scale" :min="0.1" :max="3" :step="0.1" />
                    <label class="flex items-center gap-2 text-xs">
                        <input v-model="set.alignToNormal" type="checkbox" class="accent-editor-accent" />
                        <span class="text-editor-muted">Align to surface normal</span>
                    </label>
                    <p v-if="index === store.activeScatter" class="text-[11px] text-editor-muted">
                        {{ scatterCount.toLocaleString() }} instances
                    </p>
                </div>
            </div>
        </div>

        <div class="p-2 border-t border-editor-border flex flex-col gap-1">
            <Button :icon="Save" variant="primary" block :disabled="store.busy" @click="save">
                {{ store.dirty ? 'Save terrain •' : 'Save terrain' }}
            </Button>
            <Button :icon="MapPin" block :disabled="store.busy" @click="place">Place in scene</Button>
            <Button :icon="Box" block :disabled="store.busy" @click="bake(false)">Bake single mesh</Button>
            <Button :icon="Boxes" block :disabled="store.busy" @click="bake(true)">Bake chunked meshes</Button>
        </div>
    </div>
</template>
