<script setup lang="ts">
import { computed, type Component } from 'vue';

/**
 * Square, icon-only button for dense toolbar rows. Pass a Lucide component as
 * `icon`; always supply a `label` for the tooltip/accessibility (icon-only
 * controls are otherwise unreadable).
 */
const props = withDefaults(
    defineProps<{
        icon: Component;
        label: string;
        size?: 'sm' | 'md';
        variant?: 'ghost' | 'default';
        disabled?: boolean;
        active?: boolean;
    }>(),
    {
        size: 'sm',
        variant: 'ghost',
        disabled: false,
        active: false,
    },
);

const boxClasses = computed(() => (props.size === 'md' ? 'h-8 w-8' : 'h-7 w-7'));
const iconSize = computed(() => (props.size === 'md' ? 17 : 15));

const stateClasses = computed(() => {
    if (props.active) return 'bg-editor-accent text-white hover:bg-editor-accent-hover';
    if (props.variant === 'default') {
        return 'bg-editor-elevated text-editor-text border border-editor-border hover:bg-editor-active';
    }
    return 'text-editor-text hover:bg-editor-hover active:bg-editor-active';
});
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :title="label"
        :aria-label="label"
        class="inline-flex items-center justify-center rounded-md transition-colors
               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-editor-accent
               focus-visible:ring-offset-1 focus-visible:ring-offset-editor-bg
               disabled:opacity-40 disabled:pointer-events-none"
        :class="[boxClasses, stateClasses]"
    >
        <component :is="icon" :size="iconSize" :stroke-width="2" />
    </button>
</template>
