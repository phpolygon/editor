import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { MaterialData } from '@/types';
import { defaultMaterial, MATERIAL_PRESETS, type MaterialPresetName } from '@/material/presets';
import { emptyMatGraph, evalMatGraph, type MatGraph } from '@/material/matGraph';
import { saveMaterial, listMaterialAssets, getMaterial } from '@/bridge/commands';
import { invalidateMaterial } from '@/three/materialCache';

/**
 * State for the material editor workspace: the material being authored, the
 * preset it started from, and the saved material assets. Editing is direct on
 * the MaterialData; the preview viewport rebuilds a three.js material from it.
 */
export const useMaterialEditorStore = defineStore('materialEditor', () => {
    const material = ref<MaterialData>(MATERIAL_PRESETS.default());
    const currentPreset = ref<string | null>('default');
    const assets = ref<{ id: string; path: string }[]>([]);

    // Graph mode: a typed node graph drives `material` (evaluated on every edit)
    // instead of the sliders. The preview + save always read `material`.
    const graphMode = ref(false);
    const graph = ref<MatGraph>(emptyMatGraph());

    function reevaluate() {
        material.value = { ...evalMatGraph(graph.value, material.value.id), id: material.value.id };
    }

    function setGraphMode(on: boolean) {
        graphMode.value = on;
        if (on) reevaluate();
    }

    function setGraph(next: MatGraph) {
        graph.value = next;
        if (graphMode.value) reevaluate();
    }

    function applyPreset(name: MaterialPresetName) {
        graphMode.value = false;
        material.value = MATERIAL_PRESETS[name]();
        currentPreset.value = name;
    }

    function reset() {
        graphMode.value = false;
        material.value = defaultMaterial();
        currentPreset.value = 'default';
    }

    async function refreshAssets() {
        try {
            assets.value = (await listMaterialAssets()).materials;
        } catch {
            assets.value = [];
        }
    }

    /** Persist the material, invalidate its cached viewport instance, refresh. */
    async function save() {
        const saved = await saveMaterial(material.value);
        invalidateMaterial(saved.id);
        await refreshAssets();
        return saved;
    }

    async function load(id: string) {
        material.value = await getMaterial(id);
        currentPreset.value = null;
    }

    return {
        material,
        currentPreset,
        assets,
        graphMode,
        graph,
        applyPreset,
        reset,
        refreshAssets,
        save,
        load,
        setGraphMode,
        setGraph,
    };
});
