<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue';

/** Draws mono float samples as a waveform, redrawing on data or resize. */
const props = defineProps<{ samples: Float32Array }>();

const canvas = ref<HTMLCanvasElement | null>(null);

function draw() {
    const c = canvas.value;
    if (!c) return;
    const ctx = c.getContext('2d');
    if (!ctx) return;

    const dpr = window.devicePixelRatio || 1;
    const w = (c.width = Math.max(1, c.clientWidth * dpr));
    const h = (c.height = Math.max(1, c.clientHeight * dpr));
    ctx.clearRect(0, 0, w, h);

    // Midline.
    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = dpr;
    ctx.beginPath();
    ctx.moveTo(0, h / 2);
    ctx.lineTo(w, h / 2);
    ctx.stroke();

    const s = props.samples;
    if (!s.length) return;

    ctx.strokeStyle = '#4c8dff';
    ctx.lineWidth = Math.max(1, dpr);
    ctx.beginPath();
    const step = s.length / w;
    for (let x = 0; x < w; x++) {
        const v = s[Math.floor(x * step)] ?? 0;
        const y = h / 2 - v * (h / 2) * 0.92;
        if (x === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    }
    ctx.stroke();
}

onMounted(() => {
    draw();
    window.addEventListener('resize', draw);
});
onUnmounted(() => window.removeEventListener('resize', draw));
watch(() => props.samples, () => draw(), { flush: 'post' });
</script>

<template>
    <canvas ref="canvas" class="block w-full h-full" />
</template>
