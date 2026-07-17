<script setup lang="ts">
import { computed, type Component } from 'vue';

/**
 * Base text button for the editor's design system. Variants map to intent
 * (neutral surface / accent / ghost / danger); sizes to density. An optional
 * `icon` (a Lucide component) renders before the label.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'default' | 'primary' | 'ghost' | 'danger' | 'success';
        size?: 'sm' | 'md';
        icon?: Component;
        disabled?: boolean;
        block?: boolean;
        type?: 'button' | 'submit';
        active?: boolean;
    }>(),
    {
        variant: 'default',
        size: 'sm',
        disabled: false,
        block: false,
        type: 'button',
        active: false,
    },
);

const sizeClasses = computed(() =>
    props.size === 'md' ? 'h-8 px-3 text-sm gap-2' : 'h-7 px-2.5 text-xs gap-1.5',
);

const variantClasses = computed(() => {
    if (props.active) {
        return 'bg-editor-accent text-white hover:bg-editor-accent-hover';
    }
    switch (props.variant) {
        case 'primary':
            return 'bg-editor-accent text-white hover:bg-editor-accent-hover';
        case 'danger':
            return 'bg-editor-danger text-white hover:brightness-110';
        case 'success':
            return 'bg-editor-success text-white hover:brightness-110';
        case 'ghost':
            return 'bg-transparent text-editor-text hover:bg-editor-hover active:bg-editor-active';
        default:
            return 'bg-editor-elevated text-editor-text hover:bg-editor-active border border-editor-border';
    }
});

const iconSize = computed(() => (props.size === 'md' ? 16 : 14));
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex items-center justify-center rounded-md font-medium whitespace-nowrap select-none
               transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-editor-accent
               focus-visible:ring-offset-1 focus-visible:ring-offset-editor-bg
               disabled:opacity-40 disabled:pointer-events-none"
        :class="[sizeClasses, variantClasses, block ? 'w-full' : '']"
    >
        <component :is="icon" v-if="icon" :size="iconSize" :stroke-width="2" />
        <slot />
    </button>
</template>
