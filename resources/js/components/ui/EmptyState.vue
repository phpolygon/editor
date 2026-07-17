<script setup lang="ts">
import { type Component } from 'vue';

/**
 * Friendly placeholder for empty panels/views: an icon, a headline, an
 * optional hint, and a `#actions` slot for call-to-action buttons. Replaces
 * the bare grey "No X" strings that made empty states feel dead.
 */
withDefaults(
    defineProps<{
        icon?: Component;
        title: string;
        hint?: string;
        compact?: boolean;
    }>(),
    { compact: false },
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center text-center h-full w-full select-none"
        :class="compact ? 'gap-1.5 p-4' : 'gap-3 p-8'"
    >
        <div
            v-if="icon"
            class="flex items-center justify-center rounded-xl bg-editor-elevated text-editor-muted"
            :class="compact ? 'h-9 w-9' : 'h-14 w-14'"
        >
            <component :is="icon" :size="compact ? 18 : 26" :stroke-width="1.75" />
        </div>
        <div class="flex flex-col gap-1">
            <p class="text-editor-text font-medium" :class="compact ? 'text-xs' : 'text-sm'">
                {{ title }}
            </p>
            <p v-if="hint" class="text-editor-muted max-w-xs" :class="compact ? 'text-[11px]' : 'text-xs'">
                {{ hint }}
            </p>
        </div>
        <div class="mt-1 flex items-center gap-2 flex-wrap justify-center">
            <slot name="actions" />
        </div>
    </div>
</template>
