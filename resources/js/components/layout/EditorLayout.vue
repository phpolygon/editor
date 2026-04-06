<script setup lang="ts">
import { onMounted, onUnmounted, provide, ref } from 'vue';
import Toolbar from './Toolbar.vue';
import HierarchyPanel from '@/components/hierarchy/HierarchyPanel.vue';
import InspectorPanel from '@/components/inspector/InspectorPanel.vue';
import SceneViewPanel from '@/components/scene/SceneViewPanel.vue';
import AssetBrowserPanel from '@/components/assets/AssetBrowserPanel.vue';
import ToastContainer from '@/components/ui/ToastContainer.vue';
import ContextMenu from '@/components/ui/ContextMenu.vue';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useToast } from '@/composables/useToast';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
const { addToast } = useToast();

// F2 rename trigger: hierarchy nodes watch this ref
const renameTriggerId = ref(0);
provide('renameTriggerId', renameTriggerId);

useKeyboardShortcuts({
    'ctrl+s': async () => {
        try {
            await sceneStore.save();
            addToast('Scene saved', 'success');
        } catch {
            addToast('Save failed', 'error');
        }
    },
    'ctrl+z': () => sceneStore.undoAction(),
    'ctrl+shift+z': () => sceneStore.redoAction(),
    'delete': () => {
        if (selectionStore.selectedEntity) {
            sceneStore.deleteEntity(selectionStore.selectedEntity);
            selectionStore.clearSelection();
        }
    },
    'f2': () => {
        if (selectionStore.selectedEntity) {
            renameTriggerId.value++;
        }
    },
});

// Unsaved changes warning
function onBeforeUnload(e: BeforeUnloadEvent) {
    if (sceneStore.dirty) {
        e.preventDefault();
    }
}

onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onUnmounted(() => window.removeEventListener('beforeunload', onBeforeUnload));
</script>

<template>
    <div class="h-screen w-screen flex flex-col bg-editor-bg text-editor-text overflow-hidden">
        <!-- Toolbar -->
        <Toolbar />

        <!-- Main grid: hierarchy | scene | inspector -->
        <div class="flex-1 grid grid-cols-[250px_1fr_300px] grid-rows-[1fr_200px] min-h-0">
            <!-- Hierarchy (left, spans both rows) -->
            <div class="row-span-2 border-r border-editor-border overflow-hidden">
                <HierarchyPanel />
            </div>

            <!-- Scene view (center top) -->
            <div class="border-b border-editor-border overflow-hidden">
                <SceneViewPanel />
            </div>

            <!-- Inspector (right, spans both rows) -->
            <div class="row-span-2 border-l border-editor-border overflow-hidden">
                <InspectorPanel />
            </div>

            <!-- Asset browser (center bottom) -->
            <div class="overflow-hidden">
                <AssetBrowserPanel />
            </div>
        </div>

        <ToastContainer />
        <ContextMenu />
    </div>
</template>
