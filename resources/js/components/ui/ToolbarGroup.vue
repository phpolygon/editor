<script setup lang="ts">
/** Groups related toolbar controls and draws a trailing separator, so the
 * toolbar reads as distinct clusters (project | workspace | scene | …) instead
 * of one undifferentiated row. Set `:divider="false"` on the last group. */
withDefaults(defineProps<{ divider?: boolean }>(), { divider: true });
</script>

<template>
    <div class="flex items-center gap-1">
        <slot />
    </div>
    <div v-if="divider" class="w-px h-5 bg-editor-border mx-1.5 shrink-0" />
</template>
