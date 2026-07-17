<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    SHADER_NODE_TYPES,
    connectShader,
    removeShaderNode,
    setShaderParam,
    setShaderPosition,
    type ShaderGraph,
    type ShaderNode,
    type GlslType,
} from '@/shader/shaderGraph';

/** Typed node-graph editor that generates GLSL (v-model on a ShaderGraph).
 * Ports carry a GLSL type (float / vec3); only same-typed ports connect. */
const props = defineProps<{ modelValue: ShaderGraph }>();
const emit = defineEmits<{ 'update:modelValue': [ShaderGraph] }>();

const NODE_W = 150;
const HEADER = 22;
const BODY = 28;
const PORT_H = 18;

const graph = computed(() => props.modelValue);
const commit = (next: ShaderGraph) => emit('update:modelValue', next);

const portColor = (t: GlslType) => (t === 'vec3' ? '#f59e0b' : '#38bdf8');
const hasBody = (type: string) => type === 'float' || type === 'color';

interface Placed {
    node: ShaderNode;
    x: number;
    y: number;
    height: number;
    def: (typeof SHADER_NODE_TYPES)[string];
}

const placed = computed<Placed[]>(() =>
    graph.value.nodes
        .map((node, i) => {
            const def = SHADER_NODE_TYPES[node.type];
            if (!def) return null;
            const rows = Math.max(def.inputs.length, def.outputs.length, 1);
            const height = HEADER + (hasBody(node.type) ? BODY : 0) + rows * PORT_H + 6;
            const drag = dragging.value?.id === node.id ? dragging.value : null;
            return {
                node,
                x: drag ? drag.x : node.x ?? 20 + (i % 2) * 170,
                y: drag ? drag.y : node.y ?? 20 + i * 56,
                height,
                def,
            };
        })
        .filter((p): p is Placed => p !== null),
);

const posById = computed(() => new Map(placed.value.map((p) => [p.node.id, p])));

function portY(p: Placed, index: number): number {
    return p.y + HEADER + (hasBody(p.node.type) ? BODY : 0) + index * PORT_H + PORT_H / 2;
}

const edges = computed(() =>
    graph.value.connections
        .map((c) => {
            const from = posById.value.get(c.from.node);
            const to = posById.value.get(c.to.node);
            if (!from || !to) return null;
            const oi = from.def.outputs.findIndex((o) => o.key === c.from.port);
            const ii = to.def.inputs.findIndex((o) => o.key === c.to.port);
            return { x1: from.x + NODE_W, y1: portY(from, oi < 0 ? 0 : oi), x2: to.x, y2: portY(to, ii < 0 ? 0 : ii) };
        })
        .filter((e): e is NonNullable<typeof e> => e !== null),
);

const canvasSize = computed(() => {
    let w = 320;
    let h = 200;
    for (const p of placed.value) {
        w = Math.max(w, p.x + NODE_W + 24);
        h = Math.max(h, p.y + p.height + 24);
    }
    return { w, h };
});

// Connect output → input.
const pending = ref<{ node: string; port: string; type: GlslType } | null>(null);
function outputClicked(node: string, port: string, type: GlslType) {
    pending.value = { node, port, type };
}
function inputClicked(node: string, port: string, type: GlslType) {
    if (!pending.value || pending.value.type !== type) return;
    commit(connectShader(graph.value, { node: pending.value.node, port: pending.value.port }, { node, port }));
    pending.value = null;
}

// Node dragging.
const dragging = ref<{ id: string; sx: number; sy: number; ox: number; oy: number; x: number; y: number } | null>(null);
function startDrag(p: Placed, ev: PointerEvent) {
    dragging.value = { id: p.node.id, sx: ev.clientX, sy: ev.clientY, ox: p.x, oy: p.y, x: p.x, y: p.y };
    (ev.target as Element).setPointerCapture?.(ev.pointerId);
}
function onDrag(ev: PointerEvent) {
    const d = dragging.value;
    if (!d) return;
    d.x = Math.max(0, d.ox + (ev.clientX - d.sx));
    d.y = Math.max(0, d.oy + (ev.clientY - d.sy));
}
function endDrag() {
    const d = dragging.value;
    if (!d) return;
    commit(setShaderPosition(graph.value, d.id, Math.round(d.x), Math.round(d.y)));
    dragging.value = null;
}

// Constant editors.
function colorHex(v: number | [number, number, number] | undefined): string {
    const c = Array.isArray(v) ? v : [0.8, 0.8, 0.8];
    const h = (x: number) => Math.round(Math.max(0, Math.min(1, x)) * 255).toString(16).padStart(2, '0');
    return `#${h(c[0])}${h(c[1])}${h(c[2])}`;
}
function onColorInput(node: ShaderNode, e: Event) {
    const hex = (e.target as HTMLInputElement).value;
    commit(setShaderParam(graph.value, node.id, 'out', [
        parseInt(hex.slice(1, 3), 16) / 255,
        parseInt(hex.slice(3, 5), 16) / 255,
        parseInt(hex.slice(5, 7), 16) / 255,
    ]));
}
function floatVal(node: ShaderNode): number {
    const v = node.params?.out;
    return typeof v === 'number' ? v : 0.5;
}
function onFloatInput(node: ShaderNode, e: Event) {
    commit(setShaderParam(graph.value, node.id, 'out', Number((e.target as HTMLInputElement).value)));
}
</script>

<template>
    <div class="flex flex-col h-full text-xs">
        <p v-if="pending" class="px-2 py-1 text-amber-400 shrink-0">Click a matching input port ({{ pending.type }})…</p>
        <div class="flex-1 overflow-auto bg-editor-bg">
            <svg :width="canvasSize.w" :height="canvasSize.h" @pointermove="onDrag" @pointerup="endDrag" @pointerleave="endDrag">
                <path
                    v-for="(e, i) in edges"
                    :key="`e${i}`"
                    :d="`M ${e.x1} ${e.y1} C ${e.x1 + 36} ${e.y1}, ${e.x2 - 36} ${e.y2}, ${e.x2} ${e.y2}`"
                    fill="none"
                    stroke="#6b7280"
                    stroke-width="1.5"
                />

                <g v-for="p in placed" :key="p.node.id" :transform="`translate(${p.x}, ${p.y})`">
                    <rect
                        :width="NODE_W"
                        :height="p.height"
                        rx="6"
                        :class="p.node.type === 'fragment' ? 'fill-editor-active stroke-emerald-400' : 'fill-editor-panel stroke-editor-border'"
                        stroke-width="1.5"
                    />
                    <rect :width="NODE_W" :height="HEADER" rx="6" fill="transparent" class="cursor-move"
                        @pointerdown.stop.prevent="startDrag(p, $event)" />
                    <text x="8" y="15" class="fill-editor-text" font-size="11">{{ p.def.label }}</text>
                    <text v-if="p.node.type !== 'fragment'" :x="NODE_W - 8" y="15" text-anchor="end" font-size="10"
                        class="fill-red-400 cursor-pointer" @click="commit(removeShaderNode(graph, p.node.id))">✕</text>

                    <foreignObject v-if="p.node.type === 'color'" x="8" :y="HEADER" :width="NODE_W - 16" :height="BODY">
                        <div xmlns="http://www.w3.org/1999/xhtml" class="pt-1">
                            <input type="color" :value="colorHex(p.node.params?.out)" class="w-full h-5 rounded cursor-pointer"
                                @input="onColorInput(p.node, $event)" />
                        </div>
                    </foreignObject>
                    <foreignObject v-else-if="p.node.type === 'float'" x="8" :y="HEADER" :width="NODE_W - 16" :height="BODY">
                        <div xmlns="http://www.w3.org/1999/xhtml" class="pt-1 flex items-center gap-1">
                            <input type="range" min="0" max="1" step="0.01" :value="floatVal(p.node)"
                                class="flex-1 accent-editor-accent" @input="onFloatInput(p.node, $event)" />
                            <span class="text-editor-muted tabular-nums text-[10px]">{{ floatVal(p.node).toFixed(2) }}</span>
                        </div>
                    </foreignObject>

                    <g v-for="(port, i) in p.def.inputs" :key="`in-${port.key}`">
                        <circle :cx="0" :cy="portY(p, i) - p.y" r="4.5" :fill="portColor(port.type)"
                            class="cursor-pointer stroke-editor-bg" stroke-width="1"
                            @click="inputClicked(p.node.id, port.key, port.type)" />
                        <text x="9" :y="portY(p, i) - p.y + 3" font-size="10" class="fill-editor-muted">{{ port.label }}</text>
                    </g>
                    <g v-for="(port, i) in p.def.outputs" :key="`out-${port.key}`">
                        <circle :cx="NODE_W" :cy="portY(p, i) - p.y" r="4.5" :fill="portColor(port.type)"
                            class="cursor-pointer stroke-editor-bg" stroke-width="1"
                            @click="outputClicked(p.node.id, port.key, port.type)" />
                    </g>
                </g>
            </svg>
        </div>
    </div>
</template>
