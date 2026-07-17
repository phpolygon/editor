<script setup lang="ts" generic="T extends string | number">
import { type Component } from 'vue';

/**
 * A compact segmented toggle (like the Scene/UI/Panels or 2D/3D switches).
 * Generic over the option value type; use with `v-model`.
 */
defineProps<{
    modelValue: T;
    options: ReadonlyArray<{ value: T; label?: string; icon?: Component; title?: string }>;
    size?: 'sm' | 'md';
    disabled?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();
</script>

<template>
    <div
        class="inline-flex items-center rounded-md bg-editor-bg border border-editor-border p-0.5 gap-0.5"
        role="group"
    >
        <button
            v-for="opt in options"
            :key="String(opt.value)"
            type="button"
            :disabled="disabled"
            :title="opt.title ?? opt.label"
            :aria-pressed="modelValue === opt.value"
            class="inline-flex items-center justify-center gap-1.5 rounded font-medium transition-colors
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-editor-accent
                   disabled:opacity-40 disabled:pointer-events-none"
            :class="[
                size === 'md' ? 'h-7 px-3 text-sm' : 'h-6 px-2.5 text-xs',
                modelValue === opt.value
                    ? 'bg-editor-accent text-white shadow-sm'
                    : 'text-editor-muted hover:text-editor-text hover:bg-editor-hover',
            ]"
            @click="emit('update:modelValue', opt.value)"
        >
            <component :is="opt.icon" v-if="opt.icon" :size="size === 'md' ? 15 : 14" :stroke-width="2" />
            <span v-if="opt.label">{{ opt.label }}</span>
        </button>
    </div>
</template>
