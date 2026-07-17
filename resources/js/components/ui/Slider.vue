<script setup lang="ts">
import { computed } from 'vue';

/** Labeled range slider with a live numeric readout, bound via `v-model`. */
const props = withDefaults(
    defineProps<{
        modelValue: number;
        label: string;
        min?: number;
        max?: number;
        step?: number;
        format?: (v: number) => string;
    }>(),
    { min: 0, max: 1, step: 0.01 },
);

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

const display = computed(() =>
    props.format ? props.format(props.modelValue) : props.modelValue.toFixed(2),
);

function onInput(e: Event) {
    emit('update:modelValue', Number((e.target as HTMLInputElement).value));
}
</script>

<template>
    <label class="flex items-center gap-2 text-xs">
        <span class="w-28 shrink-0 text-editor-muted truncate">{{ label }}</span>
        <input
            type="range"
            :min="min"
            :max="max"
            :step="step"
            :value="modelValue"
            class="flex-1 h-1.5 accent-editor-accent cursor-pointer"
            @input="onInput"
        />
        <span class="w-12 text-right tabular-nums text-editor-text">{{ display }}</span>
    </label>
</template>
