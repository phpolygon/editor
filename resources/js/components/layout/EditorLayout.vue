<script setup lang="ts">
import { computed, onMounted, onUnmounted, provide, ref } from 'vue';
import Toolbar from './Toolbar.vue';
import GameConsole from './GameConsole.vue';
import WelcomeScreen from './WelcomeScreen.vue';
import SceneLoadingOverlay from './SceneLoadingOverlay.vue';
import AssetBrowserPanel from '@/components/assets/AssetBrowserPanel.vue';
import ToastContainer from '@/components/ui/ToastContainer.vue';
import DialogHost from '@/components/ui/DialogHost.vue';
import ContextMenu from '@/components/ui/ContextMenu.vue';
import { getWorkspace } from '@/workspaces';
import { useKeyboardShortcuts } from '@/composables/useKeyboardShortcuts';
import { useToast } from '@/composables/useToast';
import { useSceneStore } from '@/stores/scene';
import { useSelectionStore } from '@/stores/selection';
import { useProjectStore } from '@/stores/project';
import { useEditorStore } from '@/stores/editor';

const sceneStore = useSceneStore();
const selectionStore = useSelectionStore();
const projectStore = useProjectStore();
const editorStore = useEditorStore();
const { addToast } = useToast();

/** How long a refused close stays "armed" before it warns again. */
const CLOSE_ARM_MS = 10_000;

// Resolve the active workspace's panel components from the registry.
const activeWorkspace = computed(() => getWorkspace(editorStore.workspace));

// F2 rename trigger: hierarchy nodes watch this ref
const renameTriggerId = ref(0);
provide('renameTriggerId', renameTriggerId);

useKeyboardShortcuts({
    'ctrl+s': async () => {
        try {
            const warning = await sceneStore.save();
            if (warning) addToast(warning, 'error');
            else addToast('Scene saved', 'success');
        } catch {
            addToast('Save failed', 'error');
        }
    },
    'ctrl+z': () => sceneStore.undoAction(),
    'ctrl+shift+z': () => sceneStore.redoAction(),
    'ctrl+d': async () => {
        const selected = [...selectionStore.selectedEntities];
        if (selected.length === 0) return;
        try {
            const copies = await sceneStore.duplicateEntities(selected);
            // Select the copies: that is what you want to move next, and it
            // matches what a duplicate does everywhere else.
            selectionStore.selectEntities(copies);
            addToast(copies.length === 1 ? `Duplicated ${copies[0]}` : `Duplicated ${copies.length} entities`, 'success');
        } catch (e: any) {
            addToast(e?.message ?? 'Duplicate failed', 'error');
        }
    },
    'delete': async () => {
        const selected = [...selectionStore.selectedEntities];
        if (selected.length === 0) return;
        selectionStore.clearSelection();
        for (const name of selected) {
            await sceneStore.deleteEntity(name);
        }
    },
    'f2': () => {
        if (selectionStore.selectedEntity) {
            renameTriggerId.value++;
        }
    },
});

// Unsaved-changes guard.
//
// Electron does not show the browser's native "leave site?" prompt, so a plain
// preventDefault() here silently refuses the close — the window simply stops
// responding to the close button for as long as the scene is dirty, with
// nothing on screen to explain it. So refuse at most once, say what is going
// on, and let the next attempt through: closing must never become impossible.
let closeArmed = false;
let closeArmTimer: number | undefined;

function onBeforeUnload(e: BeforeUnloadEvent) {
    if (!sceneStore.dirty || closeArmed) return;

    e.preventDefault();
    closeArmed = true;
    window.clearTimeout(closeArmTimer);
    closeArmTimer = window.setTimeout(() => {
        closeArmed = false;
    }, CLOSE_ARM_MS);

    addToast('Unsaved changes — Ctrl+S to save, or close again to discard them', 'error');
}

onMounted(async () => {
    window.addEventListener('beforeunload', onBeforeUnload);
    try {
        await projectStore.fetchProject();
        projectStore.fetchRecent().catch(() => {});
        if (projectStore.opened) {
            await sceneStore.fetchSceneList();
            if (projectStore.entryScene) {
                await sceneStore.load(projectStore.entryScene);
            }
        }
    } catch {
        // Ignore — user can still open a project manually.
    }
});
onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    window.clearTimeout(closeArmTimer);
});
</script>

<template>
    <div class="h-screen w-screen flex flex-col bg-editor-bg text-editor-text overflow-hidden">
        <!-- Toolbar -->
        <Toolbar />

        <!-- No project yet → friendly landing instead of empty panels. -->
        <WelcomeScreen v-if="!projectStore.opened" />

        <!-- Main grid: left sidebar | viewport (+ asset browser) | right sidebar.
             Panels are resolved from the active workspace in the registry. -->
        <div
            v-else
            class="flex-1 grid grid-cols-[250px_1fr_300px] min-h-0"
            :class="activeWorkspace.showAssetBrowser === false ? 'grid-rows-[1fr]' : 'grid-rows-[1fr_200px]'"
        >
            <!-- Left sidebar (spans both rows) -->
            <div class="row-span-2 border-r border-editor-border overflow-hidden">
                <component :is="activeWorkspace.left" />
            </div>

            <!-- Viewport (center top) -->
            <div class="border-b border-editor-border overflow-hidden">
                <component :is="activeWorkspace.center" />
            </div>

            <!-- Right sidebar (spans both rows) -->
            <div class="row-span-2 border-l border-editor-border overflow-hidden">
                <component :is="activeWorkspace.right" />
            </div>

            <!-- Asset browser (center bottom, shared across workspaces) -->
            <div v-if="activeWorkspace.showAssetBrowser !== false" class="overflow-hidden">
                <AssetBrowserPanel />
            </div>
        </div>

        <!-- Game output sits below the whole grid: it belongs to the play
             session, not to any one workspace. -->
        <GameConsole v-if="projectStore.opened" />

        <ToastContainer />
        <DialogHost />
        <ContextMenu />
        <SceneLoadingOverlay />
    </div>
</template>
