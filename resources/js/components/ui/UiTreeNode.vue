<script setup lang="ts">
import { computed, ref } from 'vue';
import type { WidgetNode } from '@/bridge/commands';
import { useUiEditorStore } from '@/stores/uiEditor';

const props = defineProps<{ node: WidgetNode; depth: number }>();
const store = useUiEditorStore();

const shortName = computed(() => props.node._widget.split('\\').pop() ?? props.node._widget);
const selected = computed(() => store.selectedId === props.node._id);
const isRoot = computed(() => props.node._id === store.root?._id);
const dropTarget = ref(false);
const caption = computed(() => {
    const n = props.node as Record<string, unknown>;
    const text = n.text ?? n.label ?? n.title;
    return typeof text === 'string' && text !== '' ? text : null;
});

function onDragStart(e: DragEvent) {
    e.dataTransfer?.setData('text/widget-id', props.node._id);
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e: DragEvent) {
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    dropTarget.value = true;
}

function onDrop(e: DragEvent) {
    e.preventDefault();
    dropTarget.value = false;
    const draggedId = e.dataTransfer?.getData('text/widget-id');
    if (draggedId) store.dropOnto(draggedId, props.node._id);
}
</script>

<template>
    <div>
        <div
            class="flex items-center gap-1 py-0.5 text-xs cursor-pointer hover:bg-editor-hover"
            :class="[selected ? 'bg-editor-active' : '', dropTarget ? 'outline outline-1 outline-editor-accent' : '']"
            :style="{ paddingLeft: `${depth * 12 + 8}px`, paddingRight: '8px' }"
            :draggable="!isRoot"
            @click="store.select(node._id)"
            @dragstart="onDragStart"
            @dragover="onDragOver"
            @dragleave="dropTarget = false"
            @drop="onDrop"
        >
            <span class="truncate">{{ shortName }}</span>
            <span v-if="caption" class="text-[10px] text-editor-muted truncate">"{{ caption }}"</span>
        </div>
        <UiTreeNode
            v-for="child in node.children ?? []"
            :key="child._id"
            :node="child"
            :depth="depth + 1"
        />
    </div>
</template>
