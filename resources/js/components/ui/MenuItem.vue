<script setup lang="ts">
import { type Component } from 'vue';

/** A single actionable row inside a `Menu`. Use `@mousedown.prevent` at the
 * call site if the trigger relies on blur-close timing. */
defineProps<{ icon?: Component; disabled?: boolean; danger?: boolean }>();
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        role="menuitem"
        class="w-full flex items-center gap-2.5 px-3 py-1.5 text-xs text-left transition-colors
               hover:bg-editor-hover disabled:opacity-40 disabled:pointer-events-none"
        :class="danger ? 'text-editor-danger' : 'text-editor-text'"
    >
        <component :is="icon" v-if="icon" :size="14" :stroke-width="2" class="shrink-0 opacity-80" />
        <span class="flex-1 truncate"><slot /></span>
    </button>
</template>
