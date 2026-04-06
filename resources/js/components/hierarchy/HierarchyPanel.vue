<script setup lang="ts">
import { ref } from 'vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import HierarchyNode from './HierarchyNode.vue';
import { useSceneStore } from '@/stores/scene';
import { useToast } from '@/composables/useToast';

const sceneStore = useSceneStore();
const { addToast } = useToast();
const newEntityName = ref('');
const showInput = ref(false);
const rootDragOver = ref(false);

async function addRootEntity() {
    if (!showInput.value) {
        showInput.value = true;
        return;
    }
    const name = newEntityName.value.trim();
    if (!name) return;
    await sceneStore.createEntity(name);
    newEntityName.value = '';
    showInput.value = false;
    addToast(`Entity "${name}" created`, 'info');
}

function cancelAdd() {
    showInput.value = false;
    newEntityName.value = '';
}

// Root-level drop: reparent to root (null parent)
function onRootDragOver(e: DragEvent) {
    e.preventDefault();
    if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
    rootDragOver.value = true;
}

function onRootDragLeave() {
    rootDragOver.value = false;
}

async function onRootDrop(e: DragEvent) {
    e.preventDefault();
    rootDragOver.value = false;
    const entityName = e.dataTransfer?.getData('text/plain');
    if (!entityName) return;

    try {
        await sceneStore.reparentEntity(entityName, null);
    } catch {
        addToast('Reparent failed', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Hierarchy" />

        <div
            class="flex-1 overflow-y-auto p-1"
            :class="{ 'bg-editor-accent/5': rootDragOver }"
            @dragover="onRootDragOver"
            @dragleave="onRootDragLeave"
            @drop="onRootDrop"
        >
            <template v-if="sceneStore.entities.length > 0">
                <HierarchyNode
                    v-for="node in sceneStore.entities"
                    :key="node.name"
                    :node="node"
                    :depth="0"
                />
            </template>
            <div v-else-if="sceneStore.loading" class="text-xs text-editor-muted p-2">
                Loading...
            </div>
            <div v-else class="text-xs text-editor-muted p-2">
                No scene loaded
            </div>
        </div>

        <!-- Add entity -->
        <div class="border-t border-editor-border p-1">
            <div v-if="showInput" class="flex gap-1">
                <input
                    v-model="newEntityName"
                    class="flex-1 bg-editor-input border border-editor-border text-editor-text text-xs px-1 py-0.5 rounded focus:border-editor-accent focus:outline-none"
                    placeholder="Entity name"
                    @keyup.enter="addRootEntity"
                    @keyup.escape="cancelAdd"
                />
                <button
                    class="text-xs px-1 hover:bg-editor-hover rounded"
                    @click="addRootEntity"
                >
                    OK
                </button>
            </div>
            <button
                v-else
                class="w-full text-xs py-1 rounded hover:bg-editor-hover active:bg-editor-active"
                @click="addRootEntity"
            >
                + Add Entity
            </button>
        </div>
    </div>
</template>
