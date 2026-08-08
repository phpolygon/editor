import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { MaterialData } from '@/types';
import { defaultMaterial, MATERIAL_PRESETS, type MaterialPresetName } from '@/material/presets';
import { emptyMatGraph, evalMatGraph, type MatGraph } from '@/material/matGraph';
import { saveMaterial, listMaterialAssets, getMaterial } from '@/bridge/commands';
import { invalidateMaterial } from '@/three/materialCache';
import type { EntityMaterialLink, EntityMaterialTarget } from '@/scene/entityAssets';
import { useSceneStore } from '@/stores/scene';

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

    // Set when the workspace was opened from an entity's inspector, so
    // `applyToEntity()` can point that entity at the material it produced.
    const linkedEntity = ref<EntityMaterialLink | null>(null);

    function clearEntityLink() {
        linkedEntity.value = null;
    }

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
        linkedEntity.value = null;
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
        linkedEntity.value = null;
        material.value = await getMaterial(id);
        currentPreset.value = null;
    }

    /**
     * Open the material a scene entity uses, and remember the entity so
     * `applyToEntity()` can write the result back.
     *
     * An entity with no material yet starts from the default preset under an id
     * derived from its name, so applying gives it its first material. An id
     * that has no asset behind it (a material the running game registers in
     * code) is treated the same way: the editor authors a fresh material under
     * that id rather than failing.
     */
    async function openForEntity(target: EntityMaterialTarget) {
        linkedEntity.value = null;

        const id = target.materialId !== '' ? target.materialId : `${target.entity}_mat`;
        try {
            const loaded = await getMaterial(id);
            if (!loaded) throw new Error(`Unknown material: ${id}`);
            // Merged onto the defaults so an asset written by an older editor
            // (or by a game, by hand) still opens with every field populated.
            material.value = { ...defaultMaterial(), ...loaded, id };
            currentPreset.value = null;
        } catch {
            material.value = { ...defaultMaterial(), id };
            currentPreset.value = 'default';
        }
        graphMode.value = false;

        linkedEntity.value = { entity: target.entity, componentClass: target.componentClass };
    }

    /** Save the material and point the linked entity's component at it. */
    async function applyToEntity(): Promise<{ materialId: string }> {
        const link = linkedEntity.value;
        if (!link) throw new Error('No entity is linked to the material editor');

        const saved = await save();
        const scene = useSceneStore();
        await scene.updateProperty(link.entity, link.componentClass, 'materialId', saved.id);
        // The viewport caches materials by id; the id it already had is often
        // the one we just rewrote, so re-sync rather than rely on the property
        // changing.
        await scene.refreshHierarchy();

        return { materialId: saved.id };
    }

    return {
        material,
        currentPreset,
        assets,
        graphMode,
        graph,
        linkedEntity,
        applyPreset,
        reset,
        refreshAssets,
        save,
        load,
        openForEntity,
        applyToEntity,
        clearEntityLink,
        setGraphMode,
        setGraph,
    };
});
