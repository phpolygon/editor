<script setup lang="ts">
import { computed, ref } from 'vue';
import { useSceneStore } from '@/stores/scene';
import type { EntityNode, ComponentData } from '@/types';
import {
    NODE_TYPES,
    addNode,
    connect,
    createNode,
    removeNode,
    setOutput,
    setParam,
    setPosition,
    uniqueNodeId,
    validate,
    type GraphNode,
    type ProceduralGraphData,
} from '@/prefab/graph';

const props = defineProps<{
    label: string;
    modelValue: GraphNode[] | null;
    entityName: string;
    componentClass: string;
}>();

const emit = defineEmits<{ 'update:model-value': [GraphNode[]] }>();

const sceneStore = useSceneStore();

const NODE_W = 168;
const NODE_HEADER = 24;
const PARAM_H = 20;
const COL_W = 220;
const ROW_H = 150;

/** Locate this component's data in the scene tree so we can read `output`. */
function findComponent(): ComponentData | undefined {
    const stack: EntityNode[] = [...sceneStore.entities];
    while (stack.length) {
        const node = stack.pop() as EntityNode;
        const comp = node.components.find((c) => c._class === props.componentClass);
        if (comp && node.name === props.entityName) return comp;
        stack.push(...node.children);
    }
    return undefined;
}

const currentOutput = computed(() => (findComponent()?.properties['output'] as string) ?? '');

const graph = computed<ProceduralGraphData>(() => ({
    nodes: props.modelValue ?? [],
    output: currentOutput.value,
}));

const validation = computed(() => validate(graph.value));

/** Column index = dependency depth; enables a simple deterministic layout. */
const depths = computed(() => {
    const map = new Map<string, number>();
    const nodesById = new Map(graph.value.nodes.map((n) => [n.id, n]));
    const depthOf = (id: string, guard: Set<string>): number => {
        if (map.has(id)) return map.get(id) as number;
        if (guard.has(id)) return 0;
        const node = nodesById.get(id);
        if (!node || !node.inputs || Object.keys(node.inputs).length === 0) {
            map.set(id, 0);
            return 0;
        }
        guard.add(id);
        const d = 1 + Math.max(...Object.values(node.inputs).map((src) => depthOf(src, guard)));
        guard.delete(id);
        map.set(id, d);
        return d;
    };
    for (const n of graph.value.nodes) depthOf(n.id, new Set());
    return map;
});

interface Placed {
    node: GraphNode;
    x: number;
    y: number;
    height: number;
    def: (typeof NODE_TYPES)[string] | undefined;
}

const placed = computed<Placed[]>(() => {
    const perColumn = new Map<number, number>();
    return graph.value.nodes.map((node) => {
        const depth = depths.value.get(node.id) ?? 0;
        const row = perColumn.get(depth) ?? 0;
        perColumn.set(depth, row + 1);
        const def = NODE_TYPES[node.type];
        const paramCount = def?.params.length ?? 0;
        const drag = dragging.value?.id === node.id ? dragging.value : null;
        // Free position if the node has one (or is being dragged); otherwise
        // fall back to auto-layout by dependency depth.
        return {
            node,
            x: drag ? drag.x : node.x ?? 12 + depth * COL_W,
            y: drag ? drag.y : node.y ?? 12 + row * ROW_H,
            height: NODE_HEADER + paramCount * PARAM_H + 8,
            def,
        };
    });
});

const posById = computed(() => {
    const map = new Map<string, Placed>();
    for (const p of placed.value) map.set(p.node.id, p);
    return map;
});

interface Edge { from: string; to: string; slot: string; x1: number; y1: number; x2: number; y2: number; }

const edges = computed<Edge[]>(() => {
    const out: Edge[] = [];
    for (const p of placed.value) {
        for (const [slot, srcId] of Object.entries(p.node.inputs ?? {})) {
            const src = posById.value.get(srcId);
            if (!src) continue;
            const slotIndex = slotsFor(p.node).indexOf(slot);
            out.push({
                from: srcId,
                to: p.node.id,
                slot,
                x1: src.x + NODE_W,
                y1: src.y + NODE_HEADER / 2,
                x2: p.x,
                y2: p.y + NODE_HEADER / 2 + (slotIndex < 0 ? 0 : slotIndex) * 12,
            });
        }
    }
    return out;
});

const canvasSize = computed(() => {
    let w = 400;
    let h = 200;
    for (const p of placed.value) {
        w = Math.max(w, p.x + NODE_W + 24);
        h = Math.max(h, p.y + p.height + 24);
    }
    return { w, h };
});

// ── Mutations ────────────────────────────────────────────────────────────
function commit(next: ProceduralGraphData) {
    emit('update:model-value', next.nodes);
    if (next.output !== graph.value.output) {
        void sceneStore.updateProperty(props.entityName, props.componentClass, 'output', next.output);
    }
}

function onAdd(type: string) {
    const id = uniqueNodeId(graph.value, type);
    commit(addNode(graph.value, createNode(type, id)));
}

function onRemove(id: string) {
    if (pendingSource.value?.target === id) pendingSource.value = null;
    commit(removeNode(graph.value, id));
}

function onSetOutput(id: string) {
    commit(setOutput(graph.value, id));
}

function onParam(id: string, key: string, value: number) {
    if (Number.isNaN(value)) return;
    commit(setParam(graph.value, id, key, value));
}

// Free node dragging (header handle). Positions commit to the graph on release.
const dragging = ref<{ id: string; sx: number; sy: number; ox: number; oy: number; x: number; y: number } | null>(null);

function startDrag(id: string, p: Placed, ev: PointerEvent) {
    dragging.value = { id, sx: ev.clientX, sy: ev.clientY, ox: p.x, oy: p.y, x: p.x, y: p.y };
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
    commit(setPosition(graph.value, d.id, Math.round(d.x), Math.round(d.y)));
    dragging.value = null;
}

// Two-click connect: click an input slot, then click a source node's output.
const pendingSource = ref<{ target: string; slot: string } | null>(null);

function slotClicked(target: string, slot: string) {
    pendingSource.value = { target, slot };
}

function outputClicked(sourceId: string) {
    const pending = pendingSource.value;
    if (!pending || pending.target === sourceId) return;
    commit(connect(graph.value, pending.target, pending.slot, sourceId));
    pendingSource.value = null;
}

/** Fixed slots for a node, plus one extra open slot for variadic 'combine'. */
function slotsFor(node: GraphNode): string[] {
    const def = NODE_TYPES[node.type];
    if (!def) return Object.keys(node.inputs ?? {});
    if (def.variadic) {
        const used = Object.keys(node.inputs ?? {});
        return [...used, `in${used.length}`];
    }
    return def.inputs;
}
</script>

<template>
    <div class="text-xs" data-testid="node-graph-field">
        <div class="flex items-center gap-1 mb-1">
            <span class="text-editor-muted">{{ label }}</span>
            <span class="flex-1" />
            <select
                class="bg-editor-input border border-editor-border rounded px-1 py-0.5"
                data-testid="add-node"
                @change="onAdd(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"
            >
                <option value="">+ node</option>
                <option v-for="def in NODE_TYPES" :key="def.type" :value="def.type">{{ def.label }}</option>
            </select>
        </div>

        <p v-if="pendingSource" class="text-amber-400 mb-1" data-testid="connect-hint">
            Pick a source node's output for '{{ pendingSource.slot }}'…
        </p>

        <div class="border border-editor-border rounded overflow-auto bg-editor-canvas" style="max-height: 420px">
            <svg
                :width="canvasSize.w"
                :height="canvasSize.h"
                data-testid="graph-svg"
                @pointermove="onDrag"
                @pointerup="endDrag"
                @pointerleave="endDrag"
            >
                <path
                    v-for="(e, i) in edges"
                    :key="`e${i}`"
                    :d="`M ${e.x1} ${e.y1} C ${e.x1 + 40} ${e.y1}, ${e.x2 - 40} ${e.y2}, ${e.x2} ${e.y2}`"
                    fill="none"
                    stroke="#6b7280"
                    stroke-width="1.5"
                />

                <g
                    v-for="p in placed"
                    :key="p.node.id"
                    :transform="`translate(${p.x}, ${p.y})`"
                    :data-testid="`node-${p.node.id}`"
                >
                    <rect
                        :width="NODE_W"
                        :height="p.height"
                        rx="4"
                        :class="graph.output === p.node.id ? 'fill-editor-active stroke-emerald-400' : 'fill-editor-panel stroke-editor-border'"
                        stroke-width="1.5"
                    />
                    <!-- header / drag handle -->
                    <rect
                        :width="NODE_W"
                        :height="NODE_HEADER"
                        rx="4"
                        fill="transparent"
                        class="cursor-move"
                        :data-testid="`drag-${p.node.id}`"
                        @pointerdown.stop.prevent="startDrag(p.node.id, p, $event)"
                    />
                    <text x="8" y="16" class="fill-editor-text" font-size="11">
                        {{ p.node.id }}<tspan class="fill-editor-muted"> · {{ p.def?.label ?? p.node.type }}</tspan>
                    </text>
                    <text
                        v-if="graph.output === p.node.id"
                        :x="NODE_W - 8"
                        y="16"
                        text-anchor="end"
                        font-size="9"
                        class="fill-emerald-400"
                    >out</text>

                    <!-- params -->
                    <foreignObject x="6" :y="NODE_HEADER" :width="NODE_W - 12" :height="p.height - NODE_HEADER">
                        <div xmlns="http://www.w3.org/1999/xhtml" class="flex flex-col gap-0.5">
                            <label
                                v-for="param in p.def?.params ?? []"
                                :key="param.key"
                                class="flex items-center justify-between gap-1"
                            >
                                <span class="text-editor-muted truncate">{{ param.label }}</span>
                                <input
                                    type="number"
                                    :step="param.step ?? 0.1"
                                    :value="p.node.params?.[param.key] ?? param.default"
                                    :data-testid="`param-${p.node.id}-${param.key}`"
                                    class="w-12 bg-editor-input border border-editor-border rounded px-1"
                                    @change="onParam(p.node.id, param.key, parseFloat(($event.target as HTMLInputElement).value))"
                                />
                            </label>
                        </div>
                    </foreignObject>

                    <!-- input slots (left) -->
                    <g v-for="(slot, si) in slotsFor(p.node)" :key="slot">
                        <circle
                            :cx="0"
                            :cy="NODE_HEADER / 2 + si * 12"
                            r="4"
                            class="fill-editor-input stroke-editor-border cursor-pointer"
                            :data-testid="`slot-${p.node.id}-${slot}`"
                            @click="slotClicked(p.node.id, slot)"
                        />
                    </g>

                    <!-- output anchor (right) -->
                    <circle
                        :cx="NODE_W"
                        :cy="NODE_HEADER / 2"
                        r="4"
                        class="fill-emerald-500 cursor-pointer"
                        :data-testid="`out-${p.node.id}`"
                        @click="outputClicked(p.node.id)"
                    />

                    <!-- actions -->
                    <foreignObject :x="NODE_W - 74" :y="p.height - 18" width="70" height="16">
                        <div xmlns="http://www.w3.org/1999/xhtml" class="flex gap-1 justify-end">
                            <button
                                class="text-emerald-400 hover:underline"
                                :data-testid="`setout-${p.node.id}`"
                                @click="onSetOutput(p.node.id)"
                            >out</button>
                            <button
                                class="text-red-400 hover:underline"
                                :data-testid="`del-${p.node.id}`"
                                @click="onRemove(p.node.id)"
                            >del</button>
                        </div>
                    </foreignObject>
                </g>
            </svg>
        </div>

        <ul v-if="!validation.ok" class="mt-1 text-red-400" data-testid="validation-errors">
            <li v-for="(err, i) in validation.errors" :key="i">{{ err }}</li>
        </ul>
    </div>
</template>
