/**
 * A node graph that GENERATES GLSL (unlike the material graph, which only
 * composes MaterialData). Each node emits one GLSL statement; the `fragment`
 * sink writes gl_FragColor. The generated fragment source runs live in a
 * three.js ShaderMaterial for preview and — persisted — becomes an engine
 * shader (GLSL → SPIR-V → HLSL/MSL via the vio transpilation).
 *
 * Types are fixed per port (float / vec3), so no inference is needed: the graph
 * builds procedural colour shaders from uv/time, constants, and math ops.
 */

export type GlslType = 'float' | 'vec3';

export interface ShaderPort {
    key: string;
    label: string;
    type: GlslType;
}

export interface ShaderNodeDef {
    type: string;
    label: string;
    category: 'input' | 'op' | 'output';
    inputs: ShaderPort[];
    outputs: ShaderPort[];
    defaults?: Record<string, number | [number, number, number]>;
}

export const SHADER_NODE_TYPES: Record<string, ShaderNodeDef> = {
    uv: {
        type: 'uv', label: 'UV', category: 'input',
        inputs: [], outputs: [{ key: 'out', label: 'UV.xy0', type: 'vec3' }],
    },
    time: {
        type: 'time', label: 'Time', category: 'input',
        inputs: [], outputs: [{ key: 'out', label: 'Seconds', type: 'float' }],
    },
    float: {
        type: 'float', label: 'Value', category: 'input',
        inputs: [], outputs: [{ key: 'out', label: 'Value', type: 'float' }],
        defaults: { out: 0.5 },
    },
    color: {
        type: 'color', label: 'Color', category: 'input',
        inputs: [], outputs: [{ key: 'out', label: 'RGB', type: 'vec3' }],
        defaults: { out: [0.8, 0.8, 0.8] },
    },
    sin: {
        type: 'sin', label: 'Sin', category: 'op',
        inputs: [{ key: 'x', label: 'x', type: 'float' }],
        outputs: [{ key: 'out', label: 'sin(x)', type: 'float' }],
        defaults: { x: 0 },
    },
    scale: {
        type: 'scale', label: 'Scale', category: 'op',
        inputs: [
            { key: 'rgb', label: 'RGB', type: 'vec3' },
            { key: 'k', label: 'k', type: 'float' },
        ],
        outputs: [{ key: 'out', label: 'RGB·k', type: 'vec3' }],
        defaults: { k: 1 },
    },
    mix: {
        type: 'mix', label: 'Mix', category: 'op',
        inputs: [
            { key: 'a', label: 'A', type: 'vec3' },
            { key: 'b', label: 'B', type: 'vec3' },
            { key: 't', label: 'Fac', type: 'float' },
        ],
        outputs: [{ key: 'out', label: 'RGB', type: 'vec3' }],
        defaults: { t: 0.5 },
    },
    fragment: {
        type: 'fragment', label: 'Fragment', category: 'output',
        inputs: [{ key: 'color', label: 'Color', type: 'vec3' }],
        outputs: [],
    },
};

export interface ShaderNode {
    id: string;
    type: string;
    params?: Record<string, number | [number, number, number]>;
    x?: number;
    y?: number;
}

export interface ShaderConnection {
    from: { node: string; port: string };
    to: { node: string; port: string };
}

export interface ShaderGraph {
    nodes: ShaderNode[];
    connections: ShaderConnection[];
}

export function emptyShaderGraph(): ShaderGraph {
    return { nodes: [{ id: 'fragment', type: 'fragment', params: {}, x: 320, y: 40 }], connections: [] };
}

// ── Construction / mutation (pure) ─────────────────────────────────────────

function cloneGraph(g: ShaderGraph): ShaderGraph {
    return {
        nodes: g.nodes.map((n) => ({ ...n, params: n.params ? { ...n.params } : undefined })),
        connections: g.connections.map((c) => ({ from: { ...c.from }, to: { ...c.to } })),
    };
}

export function shaderNodeById(g: ShaderGraph, id: string): ShaderNode | undefined {
    return g.nodes.find((n) => n.id === id);
}

export function createShaderNode(type: string, id: string): ShaderNode {
    const def = SHADER_NODE_TYPES[type];
    const params: Record<string, number | [number, number, number]> = {};
    if (def?.defaults) for (const [k, v] of Object.entries(def.defaults)) params[k] = v;
    return { id, type, params };
}

export function uniqueShaderNodeId(g: ShaderGraph, type: string): string {
    if (!shaderNodeById(g, type)) return type;
    let i = 2;
    while (shaderNodeById(g, `${type}_${i}`)) i++;
    return `${type}_${i}`;
}

export function addShaderNode(g: ShaderGraph, node: ShaderNode): ShaderGraph {
    const next = cloneGraph(g);
    next.nodes.push(node);
    return next;
}

export function removeShaderNode(g: ShaderGraph, id: string): ShaderGraph {
    if (id === 'fragment') return g;
    const next = cloneGraph(g);
    next.nodes = next.nodes.filter((n) => n.id !== id);
    next.connections = next.connections.filter((c) => c.from.node !== id && c.to.node !== id);
    return next;
}

function shaderPortType(g: ShaderGraph, node: string, port: string, dir: 'in' | 'out'): GlslType | undefined {
    const n = shaderNodeById(g, node);
    const def = n ? SHADER_NODE_TYPES[n.type] : undefined;
    return (dir === 'out' ? def?.outputs : def?.inputs)?.find((p) => p.key === port)?.type;
}

function shaderHasCycle(g: ShaderGraph): boolean {
    const state = new Map<string, 'visiting' | 'done'>();
    const visit = (id: string): boolean => {
        const s = state.get(id);
        if (s === 'visiting') return true;
        if (s === 'done') return false;
        state.set(id, 'visiting');
        for (const c of g.connections.filter((c) => c.to.node === id)) if (visit(c.from.node)) return true;
        state.set(id, 'done');
        return false;
    };
    return g.nodes.some((n) => visit(n.id));
}

export function connectShader(
    g: ShaderGraph,
    from: { node: string; port: string },
    to: { node: string; port: string },
): ShaderGraph {
    if (from.node === to.node) return g;
    if (shaderPortType(g, from.node, from.port, 'out') !== shaderPortType(g, to.node, to.port, 'in')) return g;
    const next = cloneGraph(g);
    next.connections = next.connections.filter((c) => !(c.to.node === to.node && c.to.port === to.port));
    next.connections.push({ from, to });
    return shaderHasCycle(next) ? g : next;
}

export function setShaderParam(g: ShaderGraph, id: string, key: string, value: number | [number, number, number]): ShaderGraph {
    const next = cloneGraph(g);
    const node = shaderNodeById(next, id);
    if (node) node.params = { ...node.params, [key]: value };
    return next;
}

export function setShaderPosition(g: ShaderGraph, id: string, x: number, y: number): ShaderGraph {
    const next = cloneGraph(g);
    const node = shaderNodeById(next, id);
    if (node) {
        node.x = x;
        node.y = y;
    }
    return next;
}

function glslFloat(v: number): string {
    return Number.isInteger(v) ? `${v}.0` : `${v}`;
}
function glslVec3(v: [number, number, number]): string {
    return `vec3(${glslFloat(v[0])}, ${glslFloat(v[1])}, ${glslFloat(v[2])})`;
}

function defaultExpr(type: GlslType): string {
    return type === 'float' ? '0.0' : 'vec3(0.0)';
}

/**
 * Generate the fragment shader body (an expression for gl_FragColor's rgb).
 * Returns the full fragment shader source ready for a three.js ShaderMaterial.
 */
export function generateFragmentShader(graph: ShaderGraph): string {
    const byId = new Map(graph.nodes.map((n) => [n.id, n]));
    const sourceOf = (node: string, port: string): string | null => {
        const c = graph.connections.find((c) => c.to.node === node && c.to.port === port);
        return c ? `n_${c.from.node}` : null;
    };

    const lines: string[] = [];
    const emitted = new Set<string>();

    const emit = (id: string) => {
        if (emitted.has(id)) return;
        const node = byId.get(id);
        if (!node || node.type === 'fragment') return;

        // Emit dependencies first.
        for (const c of graph.connections.filter((c) => c.to.node === id)) {
            emit(c.from.node);
        }

        const def = SHADER_NODE_TYPES[node.type];
        const outType = def?.outputs[0]?.type ?? 'float';
        const inExpr = (key: string, type: GlslType, fallbackParam?: string): string => {
            const src = sourceOf(id, key);
            if (src) return src;
            const p = node.params?.[fallbackParam ?? key] ?? def?.defaults?.[fallbackParam ?? key];
            if (type === 'float') return glslFloat(typeof p === 'number' ? p : 0);
            return glslVec3(Array.isArray(p) ? p : [0, 0, 0]);
        };

        let expr: string;
        switch (node.type) {
            case 'uv': expr = 'vec3(vUv, 0.0)'; break;
            case 'time': expr = 'uTime'; break;
            case 'float': expr = glslFloat(typeof node.params?.out === 'number' ? node.params.out : 0.5); break;
            case 'color': expr = glslVec3(Array.isArray(node.params?.out) ? node.params.out : [0.8, 0.8, 0.8]); break;
            case 'sin': expr = `sin(${inExpr('x', 'float')})`; break;
            case 'scale': expr = `(${inExpr('rgb', 'vec3')} * ${inExpr('k', 'float')})`; break;
            case 'mix': expr = `mix(${inExpr('a', 'vec3')}, ${inExpr('b', 'vec3')}, ${inExpr('t', 'float')})`; break;
            default: expr = defaultExpr(outType);
        }

        lines.push(`    ${outType} n_${id} = ${expr};`);
        emitted.add(id);
    };

    // Resolve the fragment color.
    const colorSrc = sourceOf('fragment', 'color');
    if (colorSrc) {
        emit(colorSrc.slice(2)); // strip "n_"
    }
    const colorExpr = colorSrc ?? 'vec3(0.8)';

    return [
        'precision highp float;',
        'varying vec2 vUv;',
        'uniform float uTime;',
        '',
        'void main() {',
        ...lines,
        `    gl_FragColor = vec4(${colorExpr}, 1.0);`,
        '}',
    ].join('\n');
}

export const VERTEX_SHADER = [
    'varying vec2 vUv;',
    'void main() {',
    '    vUv = uv;',
    '    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);',
    '}',
].join('\n');
