import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { MaterialData } from '@/types';
import { defaultMaterial, MATERIAL_PRESETS, type MaterialPresetName } from '@/material/presets';
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

    function applyPreset(name: MaterialPresetName) {
        material.value = MATERIAL_PRESETS[name]();
        currentPreset.value = name;
    }

    function reset() {
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

    return { material, currentPreset, assets, applyPreset, reset, refreshAssets, save, load };
});
