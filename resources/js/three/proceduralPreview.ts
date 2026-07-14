import * as THREE from 'three';
import { evaluateProceduralMesh } from '@/bridge/commands';
import { buildGeometry, setMesh } from './meshCache';
import type { GraphNode, ProceduralGraphData } from '@/prefab/graph';

/**
 * Evaluate a ProceduralMesh graph on the backend and turn the result into a
 * Three.js geometry for the viewport preview.
 *
 * If the graph carries a `meshId` the geometry is also stored in the shared
 * mesh cache under that id, so scene objects rendering that mesh (a
 * MeshRenderer bound to the same id) refresh to the new geometry. Returns null
 * when the graph fails to evaluate (e.g. an invalid graph mid-edit).
 */
export async function previewProceduralMesh(
    nodes: GraphNode[],
    output: string,
    meshId = '',
): Promise<THREE.BufferGeometry | null> {
    try {
        const data = await evaluateProceduralMesh(nodes as unknown[], output, meshId);
        return meshId !== '' ? setMesh(meshId, data) : buildGeometry(data);
    } catch {
        return null;
    }
}

/** Convenience overload taking a whole graph object. */
export function previewGraph(graph: ProceduralGraphData): Promise<THREE.BufferGeometry | null> {
    return previewProceduralMesh(graph.nodes, graph.output, graph.meshId ?? '');
}
