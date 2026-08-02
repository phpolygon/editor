import { defineStore } from 'pinia';
import { computed, ref, shallowRef } from 'vue';
import {
    bakeTerrainMesh,
    createTerrainEntity,
    deleteTerrainAsset,
    listTerrainAssets,
    loadTerrain,
    saveMaterial,
    saveTerrain,
    saveTexture,
    type TerrainLayerPayload,
    type TerrainPayload,
    type TerrainScatterPayload,
} from '@/bridge/commands';
import { bakeSplatToImage, type BakedImage } from '@/terrain/bakeSplat';
import {
    DEFAULT_BRUSH,
    applyBrush,
    anchorAt,
    type BrushSettings,
    type StrokeAnchor,
} from '@/terrain/brushes';
import {
    DEFAULT_GENERATOR,
    generateHeightmap,
    heightmapFromImageData,
    heightmapToImageData,
    type GeneratorSettings,
} from '@/terrain/generators';
import {
    DEFAULT_HEIGHTMAP,
    createHeightmap,
    cloneHeightmap,
    encodeHeights,
    heightmapFromEncoded,
    reprojectRange,
    resample,
    slopeAt,
    type Heightmap,
    type HeightmapOptions,
} from '@/terrain/heightmap';
import {
    createSplatMap,
    decodeDensityMap,
    fillLayerByRules,
    normaliseSplat,
    paintLayer,
    resizeDensityMap,
    resizeSplat,
    decodeSplat,
    encodeSplat,
    type SplatMap,
} from '@/terrain/splat';
import { createScatterSet, type ScatterSet } from '@/terrain/scatter';

/**
 * State for the terrain workspace.
 *
 * The heightmap is held in a `shallowRef` and mutated in place by brushes. Deep
 * reactivity over a 66k-element Float32Array would mean a proxy trap per sample
 * write, which is exactly the wrong cost for a tool whose inner loop rewrites
 * thousands of samples per pointer move. Components therefore watch
 * {@link revision} — bumped once per committed change — instead of the buffer.
 *
 * Undo snapshots the heightmap before a *stroke*, not per brush step: a drag is
 * one user action, and per-step snapshots would both blow memory and make undo
 * useless (a single undo would rewind one frame of a drag).
 */
export const useTerrainEditorStore = defineStore('terrainEditor', () => {
    const name = ref('terrain');
    const heightmap = shallowRef<Heightmap>(createHeightmap());
    const splat = shallowRef<SplatMap>(createSplatMap(DEFAULT_HEIGHTMAP, 0));

    /** Bumped whenever the heightmap or splat data changes, to drive redraws. */
    const revision = ref(0);

    const brush = ref<BrushSettings>({ ...DEFAULT_BRUSH });
    const generator = ref<GeneratorSettings>({ ...DEFAULT_GENERATOR });

    const layers = ref<TerrainLayerPayload[]>([]);
    const scatterSets = ref<ScatterSet[]>([]);
    const materialId = ref('');
    const chunkSize = ref(32);

    /** Which tool the viewport's pointer drives. */
    const mode = ref<'sculpt' | 'paint' | 'scatter'>('sculpt');

    /**
     * Sculpting the terrain of the selected entity directly in the scene
     * viewport, in world context.
     *
     * Lives here rather than in the scene store so both editing surfaces share
     * one set of brush settings — a radius dialled in while sculpting in the
     * scene carries over to the terrain workspace and back.
     */
    const sceneSculptEnabled = ref(false);
    const activeLayer = ref(0);
    const activeScatter = ref(0);

    const assets = ref<{ name: string; path: string }[]>([]);
    const loadedAssetName = ref<string | null>(null);
    const dirty = ref(false);
    const busy = ref(false);
    const error = ref<string | null>(null);

    // Undo history of encoded snapshots. Encoded rather than raw buffers so a
    // deep history stays affordable: a 257x257 snapshot is ~180 KB of base64
    // versus 264 KB of Float32, and history is rarely replayed.
    const MAX_HISTORY = 50;
    const undoStack: TerrainSnapshot[] = [];
    const redoStack: TerrainSnapshot[] = [];
    const canUndo = ref(false);
    const canRedo = ref(false);

    interface TerrainSnapshot {
        heights: string;
        splat: string;
        layerCount: number;
    }

    const gridWidth = computed(() => heightmap.value.gridWidth);
    const gridDepth = computed(() => heightmap.value.gridDepth);
    const vertexCount = computed(() => gridWidth.value * gridDepth.value);
    const triangleCount = computed(() => (gridWidth.value - 1) * (gridDepth.value - 1) * 2);

    function touch() {
        revision.value++;
        dirty.value = true;
    }

    function snapshot(): TerrainSnapshot {
        return {
            heights: encodeHeights(heightmap.value.samples),
            splat: encodeSplat(splat.value),
            layerCount: layers.value.length,
        };
    }

    function restore(snap: TerrainSnapshot) {
        const decoded = heightmapFromEncoded(heightmap.value, snap.heights);
        heightmap.value = decoded;
        splat.value = decodeSplat(snap.splat, heightmap.value, snap.layerCount);
        touch();
    }

    function syncHistoryFlags() {
        canUndo.value = undoStack.length > 0;
        canRedo.value = redoStack.length > 0;
    }

    /**
     * Record the current state as an undo point. Call once at the *start* of a
     * user action (a stroke, a generate, a resize), never per frame.
     */
    function pushHistory() {
        undoStack.push(snapshot());
        if (undoStack.length > MAX_HISTORY) undoStack.shift();
        redoStack.length = 0;
        syncHistoryFlags();
    }

    function undo() {
        const previous = undoStack.pop();
        if (!previous) return;
        redoStack.push(snapshot());
        restore(previous);
        syncHistoryFlags();
    }

    function redo() {
        const next = redoStack.pop();
        if (!next) return;
        undoStack.push(snapshot());
        restore(next);
        syncHistoryFlags();
    }

    // ── Sculpting ───────────────────────────────────────────────────────────

    let strokeAnchor: StrokeAnchor | null = null;

    /** Begin a stroke: snapshot for undo and capture the anchor point. */
    function beginStroke(worldX: number, worldZ: number) {
        pushHistory();
        strokeAnchor = anchorAt(heightmap.value, worldX, worldZ);
    }

    function endStroke() {
        strokeAnchor = null;
    }

    /** Apply one brush step at a world position. Returns true if anything changed. */
    function sculptAt(worldX: number, worldZ: number, dt: number, invert: boolean): boolean {
        const changed = applyBrush(heightmap.value, {
            settings: brush.value,
            worldX,
            worldZ,
            dt,
            invert,
            anchor: strokeAnchor ?? undefined,
        });
        if (changed) touch();
        return changed;
    }

    /** Paint the active splat layer at a world position. */
    function paintAt(worldX: number, worldZ: number, dt: number, invert: boolean): boolean {
        if (layers.value.length === 0) return false;
        const changed = paintLayer(splat.value, heightmap.value, {
            layer: activeLayer.value,
            settings: brush.value,
            worldX,
            worldZ,
            dt,
            erase: invert,
        });
        if (changed) touch();
        return changed;
    }

    /** Paint the active scatter set's density at a world position. */
    function scatterAt(worldX: number, worldZ: number, dt: number, invert: boolean): boolean {
        const set = scatterSets.value[activeScatter.value];
        if (!set) return false;

        const changed = paintLayer(set.density, heightmap.value, {
            layer: 0,
            settings: brush.value,
            worldX,
            worldZ,
            dt,
            erase: invert,
        });
        if (changed) touch();
        return changed;
    }

    /** Dispatch a pointer drag to whichever tool is active. */
    function applyAt(worldX: number, worldZ: number, dt: number, invert: boolean): boolean {
        switch (mode.value) {
            case 'paint':
                return paintAt(worldX, worldZ, dt, invert);
            case 'scatter':
                return scatterAt(worldX, worldZ, dt, invert);
            case 'sculpt':
            default:
                return sculptAt(worldX, worldZ, dt, invert);
        }
    }

    // ── Generators and terrain settings ─────────────────────────────────────

    function generate() {
        pushHistory();
        heightmap.value = generateHeightmap(heightmap.value, generator.value);
        touch();
    }

    function setResolution(width: number, depth: number) {
        if (width === gridWidth.value && depth === gridDepth.value) return;
        pushHistory();
        heightmap.value = resample(heightmap.value, width, depth);
        splat.value = resizeSplat(splat.value, heightmap.value, layers.value.length);
        for (const set of scatterSets.value) {
            // Density resizes without normalising — an unpainted cell must stay
            // unpainted rather than being filled to full coverage.
            set.density = resizeDensityMap(set.density, heightmap.value);
        }
        touch();
    }

    function setWorldSize(sizeX: number, sizeZ: number) {
        if (sizeX <= 0 || sizeZ <= 0) return;
        pushHistory();
        heightmap.value = { ...heightmap.value, sizeX, sizeZ };
        touch();
    }

    /**
     * Change the height range while keeping the sculpted shape, so the range
     * acts as headroom rather than a scale factor on existing terrain.
     */
    function setHeightRange(minHeight: number, maxHeight: number) {
        if (maxHeight <= minHeight) return;
        pushHistory();
        heightmap.value = reprojectRange(heightmap.value, minHeight, maxHeight);
        touch();
    }

    // ── Layers ──────────────────────────────────────────────────────────────

    function addLayer(): void {
        const id = `layer_${Date.now().toString(36)}_${layers.value.length}`;
        layers.value = [
            ...layers.value,
            {
                id,
                name: `Layer ${layers.value.length + 1}`,
                materialId: '',
                uvScale: 16,
                minHeight: 0,
                maxHeight: 1,
                minSlope: 0,
                maxSlope: 90,
            },
        ];
        splat.value = resizeSplat(splat.value, heightmap.value, layers.value.length);
        activeLayer.value = layers.value.length - 1;
        touch();
    }

    function removeLayer(index: number): void {
        if (index < 0 || index >= layers.value.length) return;
        pushHistory();
        layers.value = layers.value.filter((_, i) => i !== index);
        splat.value = resizeSplat(splat.value, heightmap.value, layers.value.length, index);
        activeLayer.value = Math.max(0, Math.min(activeLayer.value, layers.value.length - 1));
        touch();
    }

    /** Fill a layer's coverage from its height/slope rules. */
    function applyLayerRules(index: number): void {
        const layer = layers.value[index];
        if (!layer) return;
        pushHistory();
        fillLayerByRules(splat.value, heightmap.value, index, layer);
        normaliseSplat(splat.value);
        touch();
    }

    // ── Scatter ─────────────────────────────────────────────────────────────

    function addScatterSet(): void {
        const id = `scatter_${Date.now().toString(36)}_${scatterSets.value.length}`;
        scatterSets.value = [
            ...scatterSets.value,
            createScatterSet(id, `Scatter ${scatterSets.value.length + 1}`, heightmap.value),
        ];
        activeScatter.value = scatterSets.value.length - 1;
        touch();
    }

    function removeScatterSet(index: number): void {
        if (index < 0 || index >= scatterSets.value.length) return;
        scatterSets.value = scatterSets.value.filter((_, i) => i !== index);
        activeScatter.value = Math.max(0, Math.min(activeScatter.value, scatterSets.value.length - 1));
        touch();
    }

    // ── Heightmap image import / export ─────────────────────────────────────

    function importHeightmapImage(image: ImageData) {
        pushHistory();
        heightmap.value = heightmapFromImageData(heightmap.value, image);
        touch();
    }

    function exportHeightmapImage(): ImageData {
        return heightmapToImageData(heightmap.value);
    }

    // ── Persistence ─────────────────────────────────────────────────────────

    function toPayload(): TerrainPayload {
        const map = heightmap.value;

        return {
            name: name.value,
            gridWidth: map.gridWidth,
            gridDepth: map.gridDepth,
            sizeX: map.sizeX,
            sizeZ: map.sizeZ,
            minHeight: map.minHeight,
            maxHeight: map.maxHeight,
            heights: encodeHeights(map.samples),
            chunkSize: chunkSize.value,
            materialId: materialId.value,
            layers: layers.value,
            splat: layers.value.length > 0 ? encodeSplat(splat.value) : '',
            scatter: scatterSets.value.map(
                (set): TerrainScatterPayload => ({
                    id: set.id,
                    name: set.name,
                    meshId: set.meshId,
                    materialId: set.materialId,
                    seed: set.seed,
                    density: set.densityPerUnit,
                    densityMap: encodeSplat(set.density),
                    minHeight: set.minHeight,
                    maxHeight: set.maxHeight,
                    minSlope: set.minSlope,
                    maxSlope: set.maxSlope,
                    minScale: set.minScale,
                    maxScale: set.maxScale,
                    alignToNormal: set.alignToNormal,
                    randomYaw: set.randomYaw,
                }),
            ),
        };
    }

    function fromPayload(payload: TerrainPayload) {
        const options: HeightmapOptions = {
            gridWidth: payload.gridWidth,
            gridDepth: payload.gridDepth,
            sizeX: payload.sizeX,
            sizeZ: payload.sizeZ,
            minHeight: payload.minHeight,
            maxHeight: payload.maxHeight,
        };

        name.value = payload.name;
        heightmap.value = heightmapFromEncoded(options, payload.heights);
        chunkSize.value = payload.chunkSize;
        materialId.value = payload.materialId;
        layers.value = payload.layers ?? [];
        splat.value = decodeSplat(payload.splat ?? '', heightmap.value, layers.value.length);
        scatterSets.value = (payload.scatter ?? []).map((set) => ({
            id: set.id,
            name: set.name,
            meshId: set.meshId,
            materialId: set.materialId,
            seed: set.seed,
            densityPerUnit: set.density,
            density: decodeDensityMap(set.densityMap, heightmap.value),
            minHeight: set.minHeight,
            maxHeight: set.maxHeight,
            minSlope: set.minSlope,
            maxSlope: set.maxSlope,
            minScale: set.minScale,
            maxScale: set.maxScale,
            alignToNormal: set.alignToNormal,
            randomYaw: set.randomYaw,
        }));

        activeLayer.value = 0;
        activeScatter.value = 0;
        undoStack.length = 0;
        redoStack.length = 0;
        syncHistoryFlags();
        dirty.value = false;
        revision.value++;
    }

    function reset() {
        name.value = 'terrain';
        heightmap.value = createHeightmap();
        splat.value = createSplatMap(DEFAULT_HEIGHTMAP, 0);
        layers.value = [];
        scatterSets.value = [];
        materialId.value = '';
        chunkSize.value = 32;
        loadedAssetName.value = null;
        undoStack.length = 0;
        redoStack.length = 0;
        syncHistoryFlags();
        dirty.value = false;
        error.value = null;
        revision.value++;
    }

    async function withBusy<T>(fn: () => Promise<T>): Promise<T | null> {
        busy.value = true;
        error.value = null;
        try {
            return await fn();
        } catch (e: unknown) {
            error.value = e instanceof Error ? e.message : 'Terrain operation failed';
            return null;
        } finally {
            busy.value = false;
        }
    }

    async function refreshAssets() {
        const result = await withBusy(() => listTerrainAssets());
        assets.value = result?.terrains ?? [];
    }

    async function save() {
        const result = await withBusy(() => saveTerrain(toPayload()));
        if (result) {
            loadedAssetName.value = result.name;
            name.value = result.name;
            dirty.value = false;
            await refreshAssets();
        }
        return result;
    }

    async function load(assetName: string) {
        const payload = await withBusy(() => loadTerrain(assetName));
        if (payload) {
            fromPayload(payload);
            loadedAssetName.value = payload.name;
        }
        return payload;
    }

    async function remove(assetName: string) {
        const result = await withBusy(() => deleteTerrainAsset(assetName));
        if (result) {
            if (loadedAssetName.value === assetName) reset();
            await refreshAssets();
        }
        return result;
    }

    /**
     * Bake the painted layers into a terrain albedo texture, save it, and point
     * the terrain's material at it.
     *
     * This is how splat layers reach the running game. A runtime splat shader
     * would need new texture bindings and shader variants in every rendering
     * backend; baking produces one ordinary texture the existing material path
     * already renders everywhere. The trade is that layers do not tile with
     * per-layer detail up close and only colour is represented — see
     * `bakeSplat.ts`.
     */
    async function bakeLayers(options: { resolution?: number; slopeShading?: number } = {}) {
        if (layers.value.length === 0) {
            error.value = 'Add at least one texture layer before baking.';
            return null;
        }

        return withBusy(async () => {
            const baked = bakeSplatToImage(heightmap.value, splat.value, layers.value, options);
            const dataUrl = await bakedImageToPngDataUrl(baked);

            const textureName = `${name.value}_albedo`;
            const texture = await saveTexture(textureName, dataUrl);

            const materialIdValue = `${name.value}_terrain`;
            await saveMaterial({
                id: materialIdValue,
                albedo: { r: 1, g: 1, b: 1, a: 1 },
                roughness: 0.9,
                metallic: 0,
                emission: { r: 0, g: 0, b: 0, a: 1 },
                alpha: 1,
                shader: 'default',
                // The baked map is the albedo; a white base colour keeps the
                // material from tinting it.
                albedoTexture: texture.relativePath,
                clearcoat: 0,
                clearcoatRoughness: 0.05,
                normalIntensity: 1,
                useEnvironmentMap: true,
                normalPattern: null,
                surfacePattern: null,
            });

            materialId.value = materialIdValue;
            dirty.value = true;

            return { texture: texture.relativePath, materialId: materialIdValue };
        });
    }

    /** Export via the engine's mesh builder, optionally writing mesh assets. */
    async function bake(options: { chunked?: boolean; save?: boolean } = {}) {
        return withBusy(() =>
            bakeTerrainMesh({
                terrain: toPayload(),
                name: name.value,
                chunked: options.chunked ?? false,
                save: options.save ?? false,
            }),
        );
    }

    /** Place the saved terrain into the active scene. */
    async function placeInScene(options: { collider?: boolean; replace?: boolean } = {}) {
        if (!loadedAssetName.value) {
            const saved = await save();
            if (!saved) return null;
        }
        return withBusy(() =>
            createTerrainEntity({
                name: loadedAssetName.value ?? name.value,
                collider: options.collider ?? true,
                replace: options.replace ?? false,
            }),
        );
    }

    /**
     * Encode baked pixels as a PNG data URL.
     *
     * Goes through a canvas because that is the only PNG encoder available in
     * the renderer; `SaveTextureCommand` accepts the data URL and strips the
     * prefix before writing the bytes. The bake itself stays DOM-free and
     * returns a plain buffer, which is wrapped here.
     */
    function bakedImageToPngDataUrl(baked: BakedImage): Promise<string> {
        const canvas = document.createElement('canvas');
        canvas.width = baked.width;
        canvas.height = baked.height;
        const context = canvas.getContext('2d');
        if (!context) return Promise.reject(new Error('Canvas 2D context unavailable'));

        context.putImageData(new ImageData(baked.data, baked.width, baked.height), 0, 0);
        return Promise.resolve(canvas.toDataURL('image/png'));
    }

    /** Slope in degrees under a world point, for the viewport readout. */
    function slopeAtWorld(worldX: number, worldZ: number): number {
        const map = heightmap.value;
        const gx = Math.round(((worldX + map.sizeX / 2) / map.sizeX) * (map.gridWidth - 1));
        const gz = Math.round(((worldZ + map.sizeZ / 2) / map.sizeZ) * (map.gridDepth - 1));
        return slopeAt(map, gx, gz);
    }

    return {
        // state
        name,
        heightmap,
        splat,
        revision,
        brush,
        generator,
        layers,
        scatterSets,
        materialId,
        chunkSize,
        mode,
        sceneSculptEnabled,
        activeLayer,
        activeScatter,
        assets,
        loadedAssetName,
        dirty,
        busy,
        error,
        canUndo,
        canRedo,
        // derived
        gridWidth,
        gridDepth,
        vertexCount,
        triangleCount,
        // sculpting
        beginStroke,
        endStroke,
        applyAt,
        sculptAt,
        paintAt,
        scatterAt,
        slopeAtWorld,
        // editing
        generate,
        setResolution,
        setWorldSize,
        setHeightRange,
        addLayer,
        removeLayer,
        applyLayerRules,
        addScatterSet,
        removeScatterSet,
        importHeightmapImage,
        exportHeightmapImage,
        undo,
        redo,
        pushHistory,
        // persistence
        toPayload,
        fromPayload,
        reset,
        refreshAssets,
        save,
        load,
        remove,
        bake,
        bakeLayers,
        placeInScene,
        // testing seam
        cloneHeightmap,
    };
});
