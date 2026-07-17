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
import { saveShader } from '@/bridge/commands';

/**
 * State for the shader workspace: a GLSL-generating node graph and its name.
 * Saving writes the generated fragment shader (+ the graph) as an asset; the
 * preview compiles the same GLSL live.
 */
export const useShaderEditorStore = defineStore('shaderEditor', () => {
    const graph = ref<ShaderGraph>(emptyShaderGraph());
    const name = ref('shader');
    const error = ref<string | null>(null);

    function setGraph(next: ShaderGraph) {
        graph.value = next;
    }

    function addNode(type: string) {
        graph.value = addShaderNode(graph.value, createShaderNode(type, uniqueShaderNodeId(graph.value, type)));
    }

    function reset() {
        graph.value = emptyShaderGraph();
        error.value = null;
    }

    async function save() {
        const { vertex, fragment } = generateEngineShaders(graph.value);
        return saveShader(name.value.trim() || 'shader', vertex, fragment, graph.value);
    }

    return { graph, name, error, setGraph, addNode, reset, save };
});
