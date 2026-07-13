<script setup lang="ts">
import { computed } from 'vue';
import type { WidgetNode } from '@/bridge/commands';
import { useUiEditorStore } from '@/stores/uiEditor';

const props = defineProps<{ node: WidgetNode }>();
const store = useUiEditorStore();

const type = computed(() => props.node._widget.split('\\').pop() ?? '');
const selected = computed(() => store.selectedId === props.node._id);
const spacing = computed(() => {
    const s = (props.node as Record<string, unknown>).spacing;
    return typeof s === 'number' ? `${s}px` : '4px';
});

function pick(e: Event) {
    e.stopPropagation();
    store.select(props.node._id);
}
</script>

<template>
    <!-- Structural preview: an approximation of the engine's layout, enough to
         see and select the tree. Exact measure/layout lives in the engine. -->
    <div
        class="rounded-sm outline-offset-[-1px]"
        :class="selected ? 'outline outline-2 outline-editor-accent' : 'outline-none'"
        :style="{ outlineColor: selected ? undefined : 'transparent' }"
        @click="pick"
    >
        <div v-if="type === 'Panel'" class="border border-editor-border rounded bg-editor-panel">
            <div v-if="node.title" class="px-2 py-1 text-[11px] font-medium bg-editor-active rounded-t">{{ node.title }}</div>
            <div class="p-2 flex flex-col" :style="{ gap: spacing }">
                <UiPreviewNode v-for="c in node.children ?? []" :key="c._id" :node="c" />
            </div>
        </div>

        <div v-else-if="type === 'VBox'" class="flex flex-col" :style="{ gap: spacing }">
            <UiPreviewNode v-for="c in node.children ?? []" :key="c._id" :node="c" />
        </div>

        <div v-else-if="type === 'HBox'" class="flex flex-row" :style="{ gap: spacing }">
            <UiPreviewNode v-for="c in node.children ?? []" :key="c._id" :node="c" />
        </div>

        <div v-else-if="type === 'Label'" class="text-xs text-editor-text px-1 py-0.5">
            {{ node.text || 'Label' }}
        </div>

        <div v-else-if="type === 'Button'" class="inline-block px-3 py-1 text-xs rounded bg-editor-accent text-white text-center">
            {{ node.label || 'Button' }}
        </div>

        <div v-else-if="type === 'Spacer'" class="flex-1 min-h-[8px] min-w-[8px]" />

        <div v-else class="px-2 py-1 text-[10px] text-editor-muted border border-dashed border-editor-border rounded">
            {{ type }}
        </div>
    </div>
</template>
