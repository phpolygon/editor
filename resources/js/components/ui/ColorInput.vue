<script setup lang="ts">
import type { Color } from '@/types';

/** Labeled color swatch bound to a normalized {r,g,b,a} Color via `v-model`.
 * Uses a native color input; alpha is preserved untouched. */
const props = defineProps<{ modelValue: Color; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [Color] }>();

function hex2(v: number): string {
    return Math.round(Math.max(0, Math.min(1, v)) * 255)
        .toString(16)
        .padStart(2, '0');
}

function toHex(c: Color): string {
    return `#${hex2(c.r)}${hex2(c.g)}${hex2(c.b)}`;
}

function onInput(e: Event) {
    const hex = (e.target as HTMLInputElement).value;
    emit('update:modelValue', {
        ...props.modelValue,
        r: parseInt(hex.slice(1, 3), 16) / 255,
        g: parseInt(hex.slice(3, 5), 16) / 255,
        b: parseInt(hex.slice(5, 7), 16) / 255,
    });
}
</script>

<template>
    <label class="flex items-center gap-2 text-xs">
        <span class="w-28 shrink-0 text-editor-muted">{{ label }}</span>
        <input
            type="color"
            :value="toHex(modelValue)"
            class="h-6 w-10 rounded border border-editor-border bg-editor-input cursor-pointer p-0"
            @input="onInput"
        />
        <span class="text-editor-muted tabular-nums">{{ toHex(modelValue) }}</span>
    </label>
</template>
