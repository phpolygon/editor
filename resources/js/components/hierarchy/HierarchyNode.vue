<script setup lang="ts">
import { ref, watch, nextTick, inject, type Ref } from 'vue';
import type { EntityNode } from '@/types';
import { useSelectionStore } from '@/stores/selection';
import { useSceneStore } from '@/stores/scene';
import { useContextMenu } from '@/composables/useContextMenu';
import { useToast } from '@/composables/useToast';

const props = defineProps<{
    node: EntityNode;
    depth: number;
}>();

const selectionStore = useSelectionStore();
const sceneStore = useSceneStore();
const { show: showContextMenu } = useContextMenu();
const { addToast } = useToast();

const expanded = ref(props.node.expanded ?? true);
const renaming = ref(false);
const renameValue = ref('');
const renameInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);

// Watch for F2 rename trigger from EditorLayout
const renameTriggerId = inject<Ref<number>>('renameTriggerId');
if (renameTriggerId) {
    watch(renameTriggerId, () => {
        if (selectionStore.selectedEntity === props.node.name) {
            startRename();
        }
    });
}

function toggle() {
    expanded.value = !expanded.value;
}

function select() {
    selectionStore.selectEntity(props.node.name);
}

const isSelected = () => selectionStore.selectedEntity === props.node.name;

// Inline rename
function startRename() {
    renameValue.value = props.node.name;
    renaming.value = true;
    nextTick(() => {
        renameInput.value?.focus();
        renameInput.value?.select();
    });
}

async function commitRename() {
    const newName = renameValue.value.trim();
    renaming.value = false;
    if (!newName || newName === props.node.name) return;

    try {
        await sceneStore.renameEntity(props.node.name, newName);
        selectionStore.selectEntity(newName);
    } catch {
        addToast('Rename failed', 'error');
    }
}

function cancelRename() {
    renaming.value = false;
}

// Context menu
function onContextMenu(e: MouseEvent) {
    select();
    showContextMenu(e, [
        { label: 'Rename', action: () => startRename(), separator: false },
        {
            label: 'Delete',
            action: async () => {
                await sceneStore.deleteEntity(props.node.name);
                if (selectionStore.selectedEntity === props.node.name) {
                    selectionStore.clearSelection();
                }
                addToast(`Entity "${props.node.name}" deleted`, 'info');
            },
            separator: false,
        },
        { label: '', action: () => {}, separator: true },
        {
            label: 'Add Child Entity',
            action: async () => {
                const name = prompt('Child entity name:');
                if (name?.trim()) {
                    await sceneStore.createEntity(name.trim(), props.node.name);
                    expanded.value = true;
                }
            },
            separator: false,
        },
    ]);
}

// Drag & Drop
function isDescendant(entityName: string, potentialAncestor: EntityNode): boolean {
    for (const child of potentialAncestor.children) {
        if (child.name === entityName) return true;
        if (isDescendant(entityName, child)) return true;
    }
    return false;
}

function onDragStart(e: DragEvent) {
    if (!e.dataTransfer) return;
    e.dataTransfer.setData('text/plain', props.node.name);
    e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e: DragEvent) {
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    dragOver.value = true;
}

function onDragLeave() {
    dragOver.value = false;
}

async function onDrop(e: DragEvent) {
    e.preventDefault();
    e.stopPropagation();
    dragOver.value = false;

    const draggedName = e.dataTransfer?.getData('text/plain');
    if (!draggedName || draggedName === props.node.name) return;

    // Prevent dropping onto own descendants
    const dragged = sceneStore.findEntity(draggedName);
    if (dragged && isDescendant(props.node.name, dragged)) return;

    try {
        await sceneStore.reparentEntity(draggedName, props.node.name);
        expanded.value = true;
    } catch {
        addToast('Reparent failed', 'error');
    }
}
</script>

<template>
    <div>
        <div
            class="flex items-center h-6 cursor-pointer text-xs select-none"
            :class="{
                'bg-editor-accent/20 border-l-2 border-editor-accent': isSelected(),
                'hover:bg-editor-hover': !isSelected(),
                'bg-editor-accent/10 border-b border-editor-accent': dragOver,
            }"
            :style="{ paddingLeft: `${depth * 16 + 4}px` }"
            draggable="true"
            @click="select"
            @dblclick.stop="startRename"
            @contextmenu.prevent="onContextMenu"
            @dragstart="onDragStart"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <!-- Expand arrow -->
            <span
                v-if="node.children.length > 0"
                class="w-4 text-center text-editor-muted cursor-pointer shrink-0"
                @click.stop="toggle"
            >
                {{ expanded ? '\u25BE' : '\u25B8' }}
            </span>
            <span v-else class="w-4 shrink-0" />

            <!-- Name or rename input -->
            <input
                v-if="renaming"
                ref="renameInput"
                v-model="renameValue"
                class="flex-1 bg-editor-input border border-editor-accent text-editor-text text-xs px-1 py-0 rounded focus:outline-none w-0 min-w-0"
                @keyup.enter="commitRename"
                @keyup.escape="cancelRename"
                @blur="commitRename"
                @click.stop
            />
            <span v-else class="truncate">{{ node.name }}</span>
        </div>

        <!-- Children -->
        <template v-if="expanded && node.children.length > 0">
            <HierarchyNode
                v-for="child in node.children"
                :key="child.name"
                :node="child"
                :depth="depth + 1"
            />
        </template>
    </div>
</template>
