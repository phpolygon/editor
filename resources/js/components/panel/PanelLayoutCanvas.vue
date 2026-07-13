<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { usePanelEditorStore } from '@/stores/panelEditor';
import { useToast } from '@/composables/useToast';
import type { PanelElement } from '@/bridge/commands';

const DESIGN_W = 1280;
const DESIGN_H = 720;

const store = usePanelEditorStore();
const { addToast } = useToast();

const viewport = ref<HTMLDivElement | null>(null);
const scale = ref(0.5);
let resizeObserver: ResizeObserver | null = null;

function recomputeScale() {
    const el = viewport.value;
    if (!el) return;
    const w = el.clientWidth - 24;
    const h = el.clientHeight - 24;
    scale.value = Math.max(0.1, Math.min(w / DESIGN_W, h / DESIGN_H));
}

onMounted(() => {
    store.fetchLayoutList();
    recomputeScale();
    resizeObserver = new ResizeObserver(recomputeScale);
    if (viewport.value) resizeObserver.observe(viewport.value);
});
onBeforeUnmount(() => resizeObserver?.disconnect());

function num(el: PanelElement, key: 'x' | 'y' | 'width' | 'height', fallback: number): number {
    const v = el[key];
    return typeof v === 'number' ? v : fallback;
}

function label(id: string): string {
    const el = store.elements[id];
    const l = el?.label;
    return typeof l === 'string' && l !== '' ? l : id;
}

// ── Drag / resize ────────────────────────────────────────────────
interface Drag {
    id: string;
    mode: 'move' | 'resize';
    px: number;
    py: number;
    start: { x: number; y: number; width: number; height: number };
}
let drag: Drag | null = null;

function startDrag(e: PointerEvent, id: string, mode: 'move' | 'resize') {
    e.preventDefault();
    e.stopPropagation();
    store.select(id);
    const el = store.elements[id];
    if (!el) return;
    drag = {
        id,
        mode,
        px: e.clientX,
        py: e.clientY,
        start: {
            x: num(el, 'x', 0),
            y: num(el, 'y', 0),
            width: num(el, 'width', 0),
            height: num(el, 'height', 0),
        },
    };
    window.addEventListener('pointermove', onDrag);
    window.addEventListener('pointerup', endDrag);
}

function onDrag(e: PointerEvent) {
    if (!drag) return;
    const dx = (e.clientX - drag.px) / scale.value;
    const dy = (e.clientY - drag.py) / scale.value;
    if (drag.mode === 'move') {
        store.setRectLocal(drag.id, {
            x: Math.round(drag.start.x + dx),
            y: Math.round(drag.start.y + dy),
        });
    } else {
        store.setRectLocal(drag.id, {
            width: Math.max(8, Math.round(drag.start.width + dx)),
            height: Math.max(8, Math.round(drag.start.height + dy)),
        });
    }
}

async function endDrag() {
    window.removeEventListener('pointermove', onDrag);
    window.removeEventListener('pointerup', endDrag);
    if (!drag) return;
    const el = store.elements[drag.id];
    const id = drag.id;
    drag = null;
    if (!el) return;
    try {
        await store.updateElement(id, {
            x: num(el, 'x', 0),
            y: num(el, 'y', 0),
            width: num(el, 'width', 0),
            height: num(el, 'height', 0),
        });
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to move element', 'error');
    }
}

// ── Layout header actions ───────────────────────────────────────
async function onSelectLayout(e: Event) {
    const name = (e.target as HTMLSelectElement).value;
    if (!name || name === store.name) return;
    if (store.dirty && !confirm('Unsaved changes will be lost. Continue?')) {
        (e.target as HTMLSelectElement).value = store.name;
        return;
    }
    try {
        await store.load(name);
    } catch (err: any) {
        addToast(err?.message ?? 'Failed to load layout', 'error');
    }
}

async function newLayout() {
    const name = window.prompt('New panel layout name:');
    if (!name) return;
    try {
        await store.create(name);
        addToast(`Created "${name}"`, 'success');
    } catch (err: any) {
        addToast(err?.message ?? 'Failed to create', 'error');
    }
}

async function save() {
    try {
        await store.save();
        addToast('Panel layout saved', 'success');
    } catch (err: any) {
        addToast(err?.message ?? 'Save failed', 'error');
    }
}

const stageStyle = computed(() => ({
    width: `${DESIGN_W * scale.value}px`,
    height: `${DESIGN_H * scale.value}px`,
}));
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader :title="store.name ? `Panel: ${store.name}` : 'Panel Layout'">
            <template #actions>
                <select
                    class="bg-editor-input border border-editor-border text-editor-text text-[11px] rounded px-1 py-0.5 focus:border-editor-accent focus:outline-none"
                    :value="store.name"
                    @change="onSelectLayout"
                >
                    <option value="" disabled>Select layout…</option>
                    <option v-for="l in store.layoutList" :key="l" :value="l">{{ l }}</option>
                </select>
                <button class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover" @click="newLayout">New</button>
                <button
                    class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                    :disabled="!store.opened"
                    @click="save"
                >
                    Save
                </button>
                <span v-if="store.dirty" class="w-2 h-2 rounded-full bg-editor-accent" title="Unsaved changes" />
            </template>
        </PanelHeader>

        <div ref="viewport" class="flex-1 overflow-auto flex items-center justify-center p-3" @pointerdown="store.select(null)">
            <div
                v-if="store.opened"
                class="relative bg-editor-bg border border-editor-border shrink-0"
                :style="stageStyle"
            >
                <div
                    v-for="id in store.elementIds"
                    :key="id"
                    class="absolute box-border border text-[10px] overflow-hidden select-none"
                    :class="store.selectedId === id
                        ? 'border-editor-accent bg-editor-accent/20 z-10'
                        : 'border-editor-border bg-editor-hover/40'"
                    :style="{
                        left: `${num(store.elements[id], 'x', 0) * scale}px`,
                        top: `${num(store.elements[id], 'y', 0) * scale}px`,
                        width: `${num(store.elements[id], 'width', 0) * scale}px`,
                        height: `${num(store.elements[id], 'height', 0) * scale}px`,
                    }"
                    @pointerdown="startDrag($event, id, 'move')"
                >
                    <span class="px-1 text-editor-text truncate pointer-events-none">{{ label(id) }}</span>
                    <div
                        v-if="store.selectedId === id"
                        class="absolute right-0 bottom-0 w-2.5 h-2.5 bg-editor-accent cursor-se-resize"
                        @pointerdown="startDrag($event, id, 'resize')"
                    />
                </div>
            </div>
            <div v-else class="text-center">
                <div class="text-xs text-editor-muted">No panel layout loaded</div>
                <div class="text-[10px] text-editor-muted">Pick one above or create a new layout</div>
            </div>
        </div>
    </div>
</template>
