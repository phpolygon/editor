<script setup lang="ts">
import { ref } from 'vue';

/**
 * Dropdown menu. The `#trigger` slot receives `{ toggle, open }`; the default
 * slot receives `{ close }` for menu items to dismiss after acting. A
 * full-viewport transparent backdrop handles click-outside without a custom
 * directive.
 */
withDefaults(defineProps<{ align?: 'left' | 'right'; width?: string }>(), {
    align: 'left',
    width: 'min-w-[200px]',
});

const open = ref(false);
const toggle = () => (open.value = !open.value);
const close = () => (open.value = false);

defineExpose({ close });
</script>

<template>
    <div class="relative inline-flex">
        <slot name="trigger" :toggle="toggle" :open="open" />
        <template v-if="open">
            <div class="fixed inset-0 z-40" @click="close" />
            <div
                class="absolute top-full mt-1.5 z-50 rounded-lg border border-editor-border bg-editor-elevated
                       shadow-xl shadow-black/40 py-1 max-h-[70vh] overflow-y-auto"
                :class="[align === 'right' ? 'right-0' : 'left-0', width]"
                role="menu"
            >
                <slot :close="close" />
            </div>
        </template>
    </div>
</template>
