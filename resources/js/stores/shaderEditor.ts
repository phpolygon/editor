import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
    emptyShaderGraph,
    generateEngineShaders,
    addShaderNode,
    createShaderNode,
    uniqueShaderNodeId,
    type ShaderGraph,
} from '@/shader/shaderGraph';
import {
    getMaterial,
    listShaderAssets,
    loadShaderAsset,
    saveMaterial,
    saveShader,
} from '@/bridge/commands';
import { invalidateMaterial } from '@/three/materialCache';
import type { EntityShaderLink, EntityShaderTarget } from '@/scene/entityAssets';
import { useSceneStore } from '@/stores/scene';

/**
 * State for the shader workspace: a GLSL-generating node graph and its name.
 * Saving writes the generated fragment shader (+ the graph) as an asset; the
 * preview compiles the same GLSL live.
 */
export const useShaderEditorStore = defineStore('shaderEditor', () => {
    const graph = ref<ShaderGraph>(emptyShaderGraph());
    const name = ref('shader');
    const error = ref<string | null>(null);
    const assets = ref<{ name: string; path: string }[]>([]);

    // Set when the workspace was opened from an entity's inspector. An entity
    // references a shader only through its material, so this remembers the
    // material to point at the shader once it is applied.
    const linkedEntity = ref<EntityShaderLink | null>(null);

    function setGraph(next: ShaderGraph) {
        graph.value = next;
    }

    function addNode(type: string) {
        graph.value = addShaderNode(graph.value, createShaderNode(type, uniqueShaderNodeId(graph.value, type)));
    }

    function reset() {
        graph.value = emptyShaderGraph();
        error.value = null;
        linkedEntity.value = null;
    }

    function clearEntityLink() {
        linkedEntity.value = null;
    }

    async function refreshAssets() {
        try {
            assets.value = (await listShaderAssets()).shaders;
        } catch {
            assets.value = [];
        }
    }

    async function save() {
        const { vertex, fragment } = generateEngineShaders(graph.value);
        const saved = await saveShader(name.value.trim() || 'shader', vertex, fragment, graph.value);
        name.value = saved.name;
        await refreshAssets();
        return saved;
    }

    /** Reopen a saved shader's authoring graph. */
    async function load(assetName: string) {
        linkedEntity.value = null;
        const data = await loadShaderAsset(assetName);
        graph.value = asGraph(data.graph);
        name.value = data.name;
        error.value = null;
    }

    /** A stored graph is JSON — fall back to an empty graph if it is not one. */
    function asGraph(value: unknown): ShaderGraph {
        if (value && typeof value === 'object') {
            const candidate = value as Partial<ShaderGraph>;
            if (Array.isArray(candidate.nodes) && Array.isArray(candidate.connections)) {
                return { nodes: candidate.nodes, connections: candidate.connections };
            }
        }
        return emptyShaderGraph();
    }

    /**
     * Open the shader used by a scene entity's material.
     *
     * The material names its shader; if that shader was authored here its graph
     * is reopened, and otherwise the editor starts empty under a name derived
     * from the material — the engine's built-in `default` shader has no graph to
     * edit, so pointing the material at a new one is the only way forward.
     */
    async function openForEntity(target: EntityShaderTarget) {
        linkedEntity.value = null;
        error.value = null;

        await refreshAssets();

        let shaderName = '';
        try {
            shaderName = (await getMaterial(target.materialId)).shader;
        } catch {
            shaderName = '';
        }

        if (shaderName !== '' && assets.value.some((a) => a.name === shaderName)) {
            await load(shaderName);
        } else {
            graph.value = emptyShaderGraph();
            name.value = shaderName !== '' && shaderName !== 'default' ? shaderName : `${target.materialId}_shader`;
        }

        linkedEntity.value = { entity: target.entity, materialId: target.materialId };
    }

    /**
     * Save the shader and point the linked entity's material at it.
     *
     * The material is what carries the reference, so this rewrites the material
     * asset rather than any component property — the entity keeps using the
     * same `materialId`.
     */
    async function applyToEntity(): Promise<{ shader: string; materialId: string }> {
        const link = linkedEntity.value;
        if (!link) throw new Error('No entity is linked to the shader editor');

        const saved = await save();

        const material = await getMaterial(link.materialId);
        await saveMaterial({ ...material, shader: saved.name });
        invalidateMaterial(link.materialId);
        await useSceneStore().refreshHierarchy();

        return { shader: saved.name, materialId: link.materialId };
    }

    return {
        graph,
        name,
        error,
        assets,
        linkedEntity,
        setGraph,
        addNode,
        reset,
        refreshAssets,
        save,
        load,
        openForEntity,
        applyToEntity,
        clearEntityLink,
    };
});
