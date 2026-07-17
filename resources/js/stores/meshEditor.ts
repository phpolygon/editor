import { defineStore } from 'pinia';
import { ref } from 'vue';
import {
    emptyGraph,
    addNode,
    createNode,
    uniqueNodeId,
    validate,
    type ProceduralGraphData,
} from '@/prefab/graph';
import { evaluateProceduralMesh } from '@/bridge/commands';
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

    return { graph, name, preview, error, evaluating, setGraph, addNodeOfType, reset, evaluate };
});
