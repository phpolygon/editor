import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
    emptyGraph,
    addNode,
    createNode,
    uniqueNodeId,
    validate,
    type GraphNode,
    type ProceduralGraphData,
} from '@/prefab/graph';
import { evaluateProceduralMesh, saveMesh, listMeshAssets, loadMeshAsset } from '@/bridge/commands';
import type { MeshData } from '@/types';

/**
 * State for the mesh editor workspace: a standalone procedural-mesh node graph
 * (independent of any entity), its evaluated preview, and validation. The graph
 * uses the same format + evaluator as the ProceduralMesh component, so meshes
 * built here can later be saved as assets or attached to entities.
 */
export const useMeshEditorStore = defineStore('meshEditor', () => {
    const graph = ref<ProceduralGraphData>(starterGraph());
    const name = ref('mesh');
    const preview = ref<MeshData | null>(null);
    const error = ref<string | null>(null);
    const evaluating = ref(false);
    const assets = ref<{ name: string; path: string }[]>([]);

    function starterGraph(): ProceduralGraphData {
        return addNode(emptyGraph(), createNode('box', 'box'));
    }

    function setGraph(next: ProceduralGraphData) {
        graph.value = next;
    }

    function addNodeOfType(type: string) {
        const id = uniqueNodeId(graph.value, type);
        graph.value = addNode(graph.value, createNode(type, id));
    }

    function reset() {
        graph.value = starterGraph();
        preview.value = null;
        error.value = null;
    }

    /** Evaluate the current graph into MeshData via the backend. */
    async function evaluate() {
        const result = validate(graph.value);
        if (!result.ok) {
            error.value = result.errors[0] ?? 'Invalid graph';
            return;
        }
        evaluating.value = true;
        error.value = null;
        try {
            preview.value = await evaluateProceduralMesh(graph.value.nodes, graph.value.output, '');
        } catch (e: any) {
            error.value = e?.message ?? 'Evaluation failed';
        } finally {
            evaluating.value = false;
        }
    }

    async function refreshAssets() {
        try {
            assets.value = (await listMeshAssets()).meshes;
        } catch {
            assets.value = [];
        }
    }

    /** Save the current graph as a reusable mesh asset. */
    async function save() {
        const result = validate(graph.value);
        if (!result.ok) {
            error.value = result.errors[0] ?? 'Invalid graph';
            throw new Error(error.value);
        }
        const saved = await saveMesh(name.value.trim() || 'mesh', graph.value.nodes, graph.value.output);
        await refreshAssets();
        return saved;
    }

    /** Load a saved mesh asset back into the editor. */
    async function load(assetName: string) {
        const data = await loadMeshAsset(assetName);
        graph.value = { nodes: data.nodes as GraphNode[], output: data.output };
        name.value = data.name;
        await evaluate();
    }

    return {
        graph,
        name,
        preview,
        error,
        evaluating,
        assets,
        setGraph,
        addNodeOfType,
        reset,
        evaluate,
        refreshAssets,
        save,
        load,
    };
});
