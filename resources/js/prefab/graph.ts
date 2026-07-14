/**
 * Client-side model of a PHPolygon procedural-mesh node graph — the data behind
 * the ProceduralMesh component (`editorHint: 'nodegraph'`). It mirrors the PHP
 * evaluator (PHPolygon\Geometry\ProceduralMeshEvaluator): same node types, same
 * validation rules (unknown node/type, cycles, missing required inputs), so the
 * canvas can validate a graph locally before the backend evaluates it.
 *
 * All mutators are pure — they return a new graph object, which keeps Vue
 * reactivity and undo/redo straightforward.
 */

/** A single node. `inputs` maps an input slot name to the source node id. */
export interface GraphNode {
    id: string;
    type: string;
    params?: Record<string, number>;
    inputs?: Record<string, string>;
    /** Optional editor canvas position. Ignored by the PHP evaluator. */
    x?: number;
    y?: number;
}

/** The serialized ProceduralMesh payload (matches the PHP component fields). */
export interface ProceduralGraphData {
    nodes: GraphNode[];
    output: string;
    meshId?: string;
}

export interface NodeParamDef {
    key: string;
    label: string;
    default: number;
    step?: number;
}

export interface NodeTypeDef {
    type: string;
    label: string;
    category: 'generator' | 'operator';
    /** Fixed input slot names. Empty for generators. */
    inputs: string[];
    /** Slots that must be connected for the graph to be valid. */
    requiredInputs: string[];
    /** True for nodes that accept any number of inputs (e.g. combine). */
    variadic: boolean;
    params: NodeParamDef[];
}

/** Node catalogue — kept in lockstep with the PHP evaluator's `build()`. */
export const NODE_TYPES: Record<string, NodeTypeDef> = {
    box: {
        type: 'box', label: 'Box', category: 'generator', inputs: [], requiredInputs: [], variadic: false,
        params: [
            { key: 'width', label: 'Width', default: 1 },
            { key: 'height', label: 'Height', default: 1 },
            { key: 'depth', label: 'Depth', default: 1 },
        ],
    },
    cylinder: {
        type: 'cylinder', label: 'Cylinder', category: 'generator', inputs: [], requiredInputs: [], variadic: false,
        params: [
            { key: 'radius', label: 'Radius', default: 0.5 },
            { key: 'height', label: 'Height', default: 1 },
            { key: 'segments', label: 'Segments', default: 16, step: 1 },
        ],
    },
    sphere: {
        type: 'sphere', label: 'Sphere', category: 'generator', inputs: [], requiredInputs: [], variadic: false,
        params: [
            { key: 'radius', label: 'Radius', default: 0.5 },
            { key: 'stacks', label: 'Stacks', default: 12, step: 1 },
            { key: 'slices', label: 'Slices', default: 16, step: 1 },
        ],
    },
    plane: {
        type: 'plane', label: 'Plane', category: 'generator', inputs: [], requiredInputs: [], variadic: false,
        params: [
            { key: 'width', label: 'Width', default: 1 },
            { key: 'depth', label: 'Depth', default: 1 },
            { key: 'subdivisions', label: 'Subdivisions', default: 1, step: 1 },
        ],
    },
    transform: {
        type: 'transform', label: 'Transform', category: 'operator', inputs: ['mesh'], requiredInputs: ['mesh'], variadic: false,
        params: [
            { key: 'tx', label: 'Translate X', default: 0 },
            { key: 'ty', label: 'Translate Y', default: 0 },
            { key: 'tz', label: 'Translate Z', default: 0 },
            { key: 'rx', label: 'Rotate X°', default: 0 },
            { key: 'ry', label: 'Rotate Y°', default: 0 },
            { key: 'rz', label: 'Rotate Z°', default: 0 },
            { key: 'sx', label: 'Scale X', default: 1 },
            { key: 'sy', label: 'Scale Y', default: 1 },
            { key: 'sz', label: 'Scale Z', default: 1 },
        ],
    },
    combine: {
        type: 'combine', label: 'Combine', category: 'operator', inputs: [], requiredInputs: [], variadic: true,
        params: [],
    },
};

export interface ValidationResult {
    ok: boolean;
    errors: string[];
}

function clone(graph: ProceduralGraphData): ProceduralGraphData {
    return {
        nodes: graph.nodes.map((n) => ({
            ...n,
            params: n.params ? { ...n.params } : undefined,
            inputs: n.inputs ? { ...n.inputs } : undefined,
        })),
        output: graph.output,
        meshId: graph.meshId,
    };
}

export function emptyGraph(): ProceduralGraphData {
    return { nodes: [], output: '', meshId: '' };
}

export function nodeById(graph: ProceduralGraphData, id: string): GraphNode | undefined {
    return graph.nodes.find((n) => n.id === id);
}

/** Build a node of the given type with its default params. */
export function createNode(type: string, id: string): GraphNode {
    const def = NODE_TYPES[type];
    const params: Record<string, number> = {};
    for (const p of def?.params ?? []) {
        params[p.key] = p.default;
    }
    return { id, type, params, inputs: {} };
}

/** Generate an id like `box`, `box_2`, … that is unique within the graph. */
export function uniqueNodeId(graph: ProceduralGraphData, type: string): string {
    if (!nodeById(graph, type)) {
        return type;
    }
    let i = 2;
    while (nodeById(graph, `${type}_${i}`)) {
        i++;
    }
    return `${type}_${i}`;
}

export function addNode(graph: ProceduralGraphData, node: GraphNode): ProceduralGraphData {
    const next = clone(graph);
    next.nodes.push({ ...node, params: { ...node.params }, inputs: { ...node.inputs } });
    if (next.output === '') {
        next.output = node.id;
    }
    return next;
}

/** Remove a node, drop any connections into it, and clear output if it was it. */
export function removeNode(graph: ProceduralGraphData, id: string): ProceduralGraphData {
    const next = clone(graph);
    next.nodes = next.nodes.filter((n) => n.id !== id);
    for (const n of next.nodes) {
        if (!n.inputs) continue;
        for (const slot of Object.keys(n.inputs)) {
            if (n.inputs[slot] === id) {
                delete n.inputs[slot];
            }
        }
    }
    if (next.output === id) {
        next.output = next.nodes.length > 0 ? next.nodes[next.nodes.length - 1].id : '';
    }
    return next;
}

export function connect(
    graph: ProceduralGraphData,
    targetId: string,
    slot: string,
    sourceId: string,
): ProceduralGraphData {
    const next = clone(graph);
    const target = nodeById(next, targetId);
    if (target) {
        target.inputs = { ...target.inputs, [slot]: sourceId };
    }
    return next;
}

export function disconnect(graph: ProceduralGraphData, targetId: string, slot: string): ProceduralGraphData {
    const next = clone(graph);
    const target = nodeById(next, targetId);
    if (target?.inputs) {
        delete target.inputs[slot];
    }
    return next;
}

export function setOutput(graph: ProceduralGraphData, id: string): ProceduralGraphData {
    const next = clone(graph);
    next.output = id;
    return next;
}

/** Set a node's editor canvas position (drag). */
export function setPosition(graph: ProceduralGraphData, id: string, x: number, y: number): ProceduralGraphData {
    const next = clone(graph);
    const node = nodeById(next, id);
    if (node) {
        node.x = x;
        node.y = y;
    }
    return next;
}

export function setParam(
    graph: ProceduralGraphData,
    id: string,
    key: string,
    value: number,
): ProceduralGraphData {
    const next = clone(graph);
    const node = nodeById(next, id);
    if (node) {
        node.params = { ...node.params, [key]: value };
    }
    return next;
}

/**
 * Validate the graph against the same rules the PHP evaluator enforces.
 * Returns every problem found (not just the first).
 */
export function validate(graph: ProceduralGraphData): ValidationResult {
    const errors: string[] = [];
    const ids = new Set(graph.nodes.map((n) => n.id));

    if (graph.output === '') {
        errors.push('Graph has no output node.');
    } else if (!ids.has(graph.output)) {
        errors.push(`Output node '${graph.output}' does not exist.`);
    }

    for (const node of graph.nodes) {
        const def = NODE_TYPES[node.type];
        if (!def) {
            errors.push(`Node '${node.id}' has unknown type '${node.type}'.`);
            continue;
        }
        for (const [slot, sourceId] of Object.entries(node.inputs ?? {})) {
            if (!ids.has(sourceId)) {
                errors.push(`Node '${node.id}' input '${slot}' points at missing node '${sourceId}'.`);
            }
        }
        for (const req of def.requiredInputs) {
            if (!node.inputs?.[req]) {
                errors.push(`Node '${node.id}' is missing required input '${req}'.`);
            }
        }
    }

    if (hasCycle(graph)) {
        errors.push('Graph contains a cycle.');
    }

    return { ok: errors.length === 0, errors };
}

function hasCycle(graph: ProceduralGraphData): boolean {
    const state = new Map<string, 'visiting' | 'done'>();

    const visit = (id: string): boolean => {
        const s = state.get(id);
        if (s === 'visiting') return true;
        if (s === 'done') return false;
        const node = nodeById(graph, id);
        if (!node) return false;
        state.set(id, 'visiting');
        for (const sourceId of Object.values(node.inputs ?? {})) {
            if (visit(sourceId)) return true;
        }
        state.set(id, 'done');
        return false;
    };

    return graph.nodes.some((n) => visit(n.id));
}

/**
 * Evaluation order (dependencies before dependents) reachable from the output
 * node. Throws on a cycle — call {@link validate} first for user-facing errors.
 */
export function topoOrder(graph: ProceduralGraphData): string[] {
    const order: string[] = [];
    const seen = new Set<string>();
    const stack = new Set<string>();

    const visit = (id: string) => {
        if (seen.has(id)) return;
        if (stack.has(id)) throw new Error(`Cycle at node '${id}'`);
        const node = nodeById(graph, id);
        if (!node) return;
        stack.add(id);
        for (const sourceId of Object.values(node.inputs ?? {})) {
            visit(sourceId);
        }
        stack.delete(id);
        seen.add(id);
        order.push(id);
    };

    if (graph.output !== '') {
        visit(graph.output);
    }
    return order;
}
