<script setup lang="ts" generic="T extends string | number">
/** Styled native <select> bound with `v-model`. Native for accessibility and
 * OS-consistent keyboard behaviour; only the chrome is themed. */
defineProps<{
    modelValue: T;
    options: ReadonlyArray<{ value: T; label: string }>;
    placeholder?: string;
    disabled?: boolean;
    size?: 'sm' | 'md';
}>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();

function onChange(e: Event) {
    emit('update:modelValue', (e.target as HTMLSelectElement).value as T);
}
</script>

<template>
    <select
        :value="modelValue"
        :disabled="disabled"
        class="rounded-md bg-editor-input border border-editor-border text-editor-text
               focus:outline-none focus:border-editor-accent focus:ring-2 focus:ring-editor-accent/40
               disabled:opacity-40 disabled:pointer-events-none transition-colors"
        :class="size === 'md' ? 'h-8 text-sm px-2.5' : 'h-7 text-xs px-2'"
        @change="onChange"
    >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <option v-for="opt in options" :key="String(opt.value)" :value="opt.value">
            {{ opt.label }}
        </option>
    </select>
</template>
