<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { useContextMenu } from '@/composables/useContextMenu';

const { x, y, visible, items, hide } = useContextMenu();

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && visible.value) {
        hide();
    }
}

function handleAction(action: () => void) {
    action();
    hide();
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div v-if="visible" class="fixed inset-0 z-50" @pointerdown.self="hide">
            <div
                class="absolute bg-editor-panel border border-editor-border rounded shadow-lg py-1 min-w-[160px]"
                :style="{ left: `${x}px`, top: `${y}px` }"
            >
                <template v-for="(item, i) in items" :key="i">
                    <div v-if="item.separator" class="h-px bg-editor-border my-1" />
                    <button
                        v-else
                        class="w-full text-left text-xs px-3 py-1.5 hover:bg-editor-accent hover:text-white disabled:opacity-40 disabled:cursor-default"
                        :disabled="item.disabled"
                        @click="handleAction(item.action)"
                    >
                        {{ item.label }}
                    </button>
                </template>
            </div>
        </div>
    </Teleport>
</template>
