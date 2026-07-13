<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { renderUiLayout, type WidgetPrimitive } from '@/bridge/commands';
import { useUiEditorStore } from '@/stores/uiEditor';

// Design space the tree is laid out in; the canvas scales this to fit its width.
const DESIGN_W = 1280;
const DESIGN_H = 720;

const store = useUiEditorStore();
const canvas = ref<HTMLCanvasElement | null>(null);
const container = ref<HTMLDivElement | null>(null);
const error = ref<string | null>(null);
let primitives: WidgetPrimitive[] = [];
let resizeObserver: ResizeObserver | null = null;

function rgba(c: unknown): string {
    const a = Array.isArray(c) ? (c as number[]) : [0, 0, 0, 1];
    const [r, g, b, al = 1] = a;
    return `rgba(${Math.round(r * 255)}, ${Math.round(g * 255)}, ${Math.round(b * 255)}, ${al})`;
}

function num(p: WidgetPrimitive, k: string): number {
    const v = p[k];
    return typeof v === 'number' ? v : 0;
}

function paint() {
    const el = canvas.value;
    const box = container.value;
    if (!el || !box) return;

    const cssW = Math.max(1, box.clientWidth);
    const scale = cssW / DESIGN_W;
    const cssH = DESIGN_H * scale;
    const dpr = window.devicePixelRatio || 1;

    el.width = Math.round(cssW * dpr);
    el.height = Math.round(cssH * dpr);
    el.style.width = `${cssW}px`;
    el.style.height = `${cssH}px`;

    const ctx = el.getContext('2d');
    if (!ctx) return;
    ctx.setTransform(dpr * scale, 0, 0, dpr * scale, 0, 0);
    ctx.clearRect(0, 0, DESIGN_W, DESIGN_H);

    for (const p of primitives) {
        drawPrimitive(ctx, p);
    }
}

function roundRectPath(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number) {
    const rr = Math.max(0, Math.min(r, w / 2, h / 2));
    ctx.beginPath();
    ctx.moveTo(x + rr, y);
    ctx.arcTo(x + w, y, x + w, y + h, rr);
    ctx.arcTo(x + w, y + h, x, y + h, rr);
    ctx.arcTo(x, y + h, x, y, rr);
    ctx.arcTo(x, y, x + w, y, rr);
    ctx.closePath();
}

function drawPrimitive(ctx: CanvasRenderingContext2D, p: WidgetPrimitive) {
    const color = rgba(p.color);
    switch (p.op) {
        case 'rect':
            ctx.fillStyle = color;
            ctx.fillRect(num(p, 'x'), num(p, 'y'), num(p, 'w'), num(p, 'h'));
            break;
        case 'rectOutline':
            ctx.strokeStyle = color;
            ctx.lineWidth = num(p, 'lineWidth') || 1;
            ctx.strokeRect(num(p, 'x'), num(p, 'y'), num(p, 'w'), num(p, 'h'));
            break;
        case 'roundRect':
            ctx.fillStyle = color;
            roundRectPath(ctx, num(p, 'x'), num(p, 'y'), num(p, 'w'), num(p, 'h'), num(p, 'radius'));
            ctx.fill();
            break;
        case 'roundRectOutline':
            ctx.strokeStyle = color;
            ctx.lineWidth = num(p, 'lineWidth') || 1;
            roundRectPath(ctx, num(p, 'x'), num(p, 'y'), num(p, 'w'), num(p, 'h'), num(p, 'radius'));
            ctx.stroke();
            break;
        case 'circle':
        case 'circleOutline':
            ctx.beginPath();
            ctx.arc(num(p, 'cx'), num(p, 'cy'), num(p, 'r'), 0, Math.PI * 2);
            if (p.op === 'circle') {
                ctx.fillStyle = color;
                ctx.fill();
            } else {
                ctx.strokeStyle = color;
                ctx.lineWidth = num(p, 'lineWidth') || 1;
                ctx.stroke();
            }
            break;
        case 'line':
            ctx.strokeStyle = color;
            ctx.lineWidth = num(p, 'width') || 1;
            ctx.beginPath();
            ctx.moveTo(num(p, 'x1'), num(p, 'y1'));
            ctx.lineTo(num(p, 'x2'), num(p, 'y2'));
            ctx.stroke();
            break;
        case 'text': {
            const size = num(p, 'size') || 14;
            ctx.fillStyle = color;
            ctx.font = `${size}px system-ui, sans-serif`;
            ctx.textAlign = p.align === 'center' ? 'center' : 'left';
            ctx.textBaseline = p.align === 'center' ? 'middle' : 'top';
            ctx.fillText(String(p.text ?? ''), num(p, 'x'), num(p, 'y'));
            break;
        }
        case 'sprite':
            ctx.strokeStyle = 'rgba(120, 140, 200, 0.7)';
            ctx.setLineDash([4, 3]);
            ctx.strokeRect(num(p, 'x'), num(p, 'y'), num(p, 'w'), num(p, 'h'));
            ctx.setLineDash([]);
            break;
        // 'arc' and other ops are ignored in the preview for now.
    }
}

async function refresh() {
    if (!store.opened) {
        primitives = [];
        paint();
        return;
    }
    try {
        error.value = null;
        const result = await renderUiLayout(DESIGN_W, DESIGN_H);
        primitives = result.primitives;
        paint();
    } catch (err: any) {
        error.value = err?.message ?? 'Preview failed';
    }
}

onMounted(() => {
    refresh();
    resizeObserver = new ResizeObserver(() => paint());
    if (container.value) resizeObserver.observe(container.value);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
});

// Re-render whenever the tree changes (edits reapply the active server-side doc).
watch(() => store.root, refresh, { deep: true });
</script>

<template>
    <div ref="container" class="w-full">
        <canvas ref="canvas" class="block rounded border border-editor-border bg-black/40" />
        <p v-if="error" class="mt-2 text-[11px] text-red-400">{{ error }}</p>
        <p class="mt-1 text-[10px] text-editor-muted">
            Preview is unfilled — bindings show as <code>{path}</code>; the game supplies the data.
        </p>
    </div>
</template>
