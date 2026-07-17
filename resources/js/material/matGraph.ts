import type { MaterialData, Color } from '@/types';
import { defaultMaterial } from './presets';

/**
 * A typed node graph that composes material PROPERTIES (not free GLSL). Ports
 * carry a type — `color` or `float` — and only same-typed ports connect. The
 * sink is a single `material` node whose input ports map to MaterialData
 * fields; evaluating the graph yields a MaterialData the viewport renders
 * directly (WYSIWYG), so it needs no engine/shader changes.
 */

export type PortType = 'color' | 'float';

export interface Port {
    key: string;
    label: string;
    type: PortType;
}

export interface MatNodeDef {
    type: string;
    label: string;
    category: 'input' | 'op' | 'output';
    inputs: Port[];
    outputs: Port[];
    /** Constant fallbacks used when an input port is unconnected. */
    defaults?: Record<string, number | Color>;
}

const black: Color = { r: 0, g: 0, b: 0, a: 1 };
const grey: Color = { r: 0.8, g: 0.8, b: 0.8, a: 1 };

export const MAT_NODE_TYPES: Record<string, MatNodeDef> = {
    colorConst: {
        type: 'colorConst', label: 'Color', category: 'input',
        inputs: [], outputs: [{ key: 'out', label: 'Color', type: 'color' }],
        defaults: { out: grey },
    },
    floatConst: {
        type: 'floatConst', label: 'Value', category: 'input',
        inputs: [], outputs: [{ key: 'out', label: 'Value', type: 'float' }],
        defaults: { out: 0.5 },
    },
    mix: {
        type: 'mix', label: 'Mix', category: 'op',
        inputs: [
            { key: 'a', label: 'A', type: 'color' },
            { key: 'b', label: 'B', type: 'color' },
            { key: 't', label: 'Fac', type: 'float' },
        ],
        outputs: [{ key: 'out', label: 'Color', type: 'color' }],
        defaults: { a: black, b: grey, t: 0.5 },
    },
    multiply: {
        type: 'multiply', label: 'Multiply', category: 'op',
        inputs: [
            { key: 'a', label: 'A', type: 'float' },
            { key: 'b', label: 'B', type: 'float' },
        ],
        outputs: [{ key: 'out', label: 'Value', type: 'float' }],
        defaults: { a: 1, b: 1 },
    },
    material: {
        type: 'material', label: 'Material', category: 'output',
        inputs: [
            { key: 'albedo', label: 'Albedo', type: 'color' },
            { key: 'roughness', label: 'Roughness', type: 'float' },
            { key: 'metallic', label: 'Metallic', type: 'float' },
            { key: 'emission', label: 'Emission', type: 'color' },
            { key: 'alpha', label: 'Alpha', type: 'float' },
            { key: 'clearcoat', label: 'Clearcoat', type: 'float' },
        ],
        outputs: [],
    },
};

export interface MatNode {
    id: string;
    type: string;
    params?: Record<string, number | Color>;
    x?: number;
    y?: number;
}

export interface MatConnection {
    from: { node: string; port: string };
    to: { node: string; port: string };
}

export interface MatGraph {
    nodes: MatNode[];
    connections: MatConnection[];
}

// ── Construction / mutation (pure) ─────────────────────────────────────────

export function emptyMatGraph(): MatGraph {
    return {
        nodes: [{ id: 'material', type: 'material', params: {}, x: 320, y: 40 }],
        connections: [],
    };
}

function clone(g: MatGraph): MatGraph {
    return {
        nodes: g.nodes.map((n) => ({ ...n, params: n.params ? { ...n.params } : undefined })),
        connections: g.connections.map((c) => ({ from: { ...c.from }, to: { ...c.to } })),
    };
}

export function matNodeById(g: MatGraph, id: string): MatNode | undefined {
    return g.nodes.find((n) => n.id === id);
}

export function createMatNode(type: string, id: string): MatNode {
    const def = MAT_NODE_TYPES[type];
    const params: Record<string, number | Color> = {};
    if (def?.defaults) {
        for (const [k, v] of Object.entries(def.defaults)) params[k] = v;
    }
    return { id, type, params };
}

export function uniqueMatNodeId(g: MatGraph, type: string): string {
    if (!matNodeById(g, type)) return type;
    let i = 2;
    while (matNodeById(g, `${type}_${i}`)) i++;
    return `${type}_${i}`;
}

export function addMatNode(g: MatGraph, node: MatNode): MatGraph {
    const next = clone(g);
    next.nodes.push(node);
    return next;
}

export function removeMatNode(g: MatGraph, id: string): MatGraph {
    if (id === 'material') return g; // the sink can't be removed
    const next = clone(g);
    next.nodes = next.nodes.filter((n) => n.id !== id);
    next.connections = next.connections.filter((c) => c.from.node !== id && c.to.node !== id);
    return next;
}

/** Connect two ports; rejects a type mismatch or an already-taken input. */
export function connectMat(
    g: MatGraph,
    from: { node: string; port: string },
    to: { node: string; port: string },
): MatGraph {
    if (from.node === to.node) return g;
    if (portType(g, from.node, from.port, 'out') !== portType(g, to.node, to.port, 'in')) return g;
    const next = clone(g);
    // One source per input port.
    next.connections = next.connections.filter((c) => !(c.to.node === to.node && c.to.port === to.port));
    next.connections.push({ from, to });
    if (hasCycle(next)) return g; // reject cycles
    return next;
}

export function setMatParam(g: MatGraph, id: string, key: string, value: number | Color): MatGraph {
    const next = clone(g);
    const node = matNodeById(next, id);
    if (node) node.params = { ...node.params, [key]: value };
    return next;
}

export function setMatPosition(g: MatGraph, id: string, x: number, y: number): MatGraph {
    const next = clone(g);
    const node = matNodeById(next, id);
    if (node) {
        node.x = x;
        node.y = y;
    }
    return next;
}

function portType(g: MatGraph, nodeId: string, port: string, dir: 'in' | 'out'): PortType | undefined {
    const node = matNodeById(g, nodeId);
    const def = node ? MAT_NODE_TYPES[node.type] : undefined;
    const list = dir === 'out' ? def?.outputs : def?.inputs;
    return list?.find((p) => p.key === port)?.type;
}

function hasCycle(g: MatGraph): boolean {
    const state = new Map<string, 'visiting' | 'done'>();
    const visit = (id: string): boolean => {
        const s = state.get(id);
        if (s === 'visiting') return true;
        if (s === 'done') return false;
        state.set(id, 'visiting');
        for (const c of g.connections.filter((c) => c.to.node === id)) {
            if (visit(c.from.node)) return true;
        }
        state.set(id, 'done');
        return false;
    };
    return g.nodes.some((n) => visit(n.id));
}

// ── Evaluation ─────────────────────────────────────────────────────────────

function asColor(v: number | Color | undefined, fallback: Color): Color {
    return v && typeof v === 'object' ? v : fallback;
}
function asFloat(v: number | Color | undefined, fallback: number): number {
    return typeof v === 'number' ? v : fallback;
}
function lerp(a: number, b: number, t: number): number {
    return a + (b - a) * t;
}
function lerpColor(a: Color, b: Color, t: number): Color {
    return { r: lerp(a.r, b.r, t), g: lerp(a.g, b.g, t), b: lerp(a.b, b.b, t), a: 1 };
}

/** Evaluate the material node's inputs into a MaterialData. */
export function evalMatGraph(g: MatGraph, id: string): MaterialData {
    const base = defaultMaterial(id);
    const output = g.nodes.find((n) => n.type === 'material');
    if (!output) return base;

    const memo = new Map<string, number | Color>();

    const evalNode = (nodeId: string, portKey: string): number | Color | undefined => {
        const cacheKey = `${nodeId}:${portKey}`;
        if (memo.has(cacheKey)) return memo.get(cacheKey);
        const node = matNodeById(g, nodeId);
        if (!node) return undefined;
        const value = compute(node);
        memo.set(cacheKey, value as number | Color);
        return value;
    };

    const inputValue = (node: MatNode, port: string): number | Color | undefined => {
        const conn = g.connections.find((c) => c.to.node === node.id && c.to.port === port);
        if (conn) return evalNode(conn.from.node, conn.from.port);
        return node.params?.[port] ?? MAT_NODE_TYPES[node.type]?.defaults?.[port];
    };

    const compute = (node: MatNode): number | Color | undefined => {
        switch (node.type) {
            case 'colorConst':
                return asColor(node.params?.out, grey);
            case 'floatConst':
                return asFloat(node.params?.out, 0.5);
            case 'mix':
                return lerpColor(
                    asColor(inputValue(node, 'a'), black),
                    asColor(inputValue(node, 'b'), grey),
                    asFloat(inputValue(node, 't'), 0.5),
                );
            case 'multiply':
                return asFloat(inputValue(node, 'a'), 1) * asFloat(inputValue(node, 'b'), 1);
            default:
                return undefined;
        }
    };

    const outInput = (port: string) => {
        const conn = g.connections.find((c) => c.to.node === output.id && c.to.port === port);
        return conn ? evalNode(conn.from.node, conn.from.port) : undefined;
    };

    const albedo = outInput('albedo');
    if (albedo) base.albedo = asColor(albedo, base.albedo);
    const emission = outInput('emission');
    if (emission) base.emission = asColor(emission, base.emission);
    const roughness = outInput('roughness');
    if (roughness !== undefined) base.roughness = asFloat(roughness, base.roughness);
    const metallic = outInput('metallic');
    if (metallic !== undefined) base.metallic = asFloat(metallic, base.metallic);
    const alpha = outInput('alpha');
    if (alpha !== undefined) base.alpha = asFloat(alpha, base.alpha);
    const clearcoat = outInput('clearcoat');
    if (clearcoat !== undefined) base.clearcoat = asFloat(clearcoat, base.clearcoat);

    return base;
}
