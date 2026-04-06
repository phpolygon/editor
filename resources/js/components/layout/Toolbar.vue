<script setup lang="ts">
import { useSceneStore } from '@/stores/scene';
import { useEditorStore } from '@/stores/editor';
import { useProjectStore } from '@/stores/project';
import { useToast } from '@/composables/useToast';

const sceneStore = useSceneStore();
const editorStore = useEditorStore();
const projectStore = useProjectStore();
const { addToast } = useToast();

function togglePlay() {
    if (editorStore.playing) {
        editorStore.stop();
    } else {
        editorStore.play();
    }
}

async function openProject() {
    try {
        await projectStore.openProjectWithDialog();
        addToast(`Project "${projectStore.name}" opened`, 'success');
    } catch (e: any) {
        if (e?.message !== 'No directory selected') {
            addToast(e?.message ?? 'Failed to open project', 'error');
        }
    }
}

async function save() {
    try {
        await sceneStore.save();
        addToast('Scene saved', 'success');
    } catch {
        addToast('Save failed', 'error');
    }
}

async function undo() {
    await sceneStore.undoAction();
}

async function redo() {
    await sceneStore.redoAction();
}

async function switchScene(e: Event) {
    const sceneName = (e.target as HTMLSelectElement).value;
    if (!sceneName || sceneName === sceneStore.name) return;

    if (sceneStore.dirty && !confirm('You have unsaved changes. Continue?')) {
        // Reset select to current scene
        (e.target as HTMLSelectElement).value = sceneStore.name;
        return;
    }

    try {
        await sceneStore.load(sceneName);
        addToast(`Scene "${sceneName}" loaded`, 'success');
    } catch {
        addToast('Failed to load scene', 'error');
    }
}
</script>

<template>
    <div class="flex items-center gap-1 px-2 h-9 bg-editor-panel border-b border-editor-border shrink-0">
        <!-- Open Project -->
        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active"
            @click="openProject"
        >
            Open Project
        </button>

        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- Play / Stop -->
        <button
            class="px-3 py-1 text-xs font-medium rounded"
            :class="editorStore.playing
                ? 'bg-red-600 hover:bg-red-700 text-white'
                : 'bg-green-600 hover:bg-green-700 text-white'"
            @click="togglePlay"
        >
            {{ editorStore.playing ? 'Stop' : 'Play' }}
        </button>

        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- Save -->
        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active disabled:opacity-40"
            :disabled="sceneStore.loading || !sceneStore.name"
            @click="save"
        >
            Save
        </button>

        <!-- Undo / Redo -->
        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active disabled:opacity-40"
            :disabled="sceneStore.loading || !sceneStore.name"
            @click="undo"
        >
            Undo
        </button>
        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active disabled:opacity-40"
            :disabled="sceneStore.loading || !sceneStore.name"
            @click="redo"
        >
            Redo
        </button>

        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- Scene selector -->
        <template v-if="projectStore.opened && sceneStore.sceneList.length > 0">
            <select
                class="bg-editor-input border border-editor-border text-editor-text text-xs rounded px-1 py-0.5 focus:border-editor-accent focus:outline-none"
                :value="sceneStore.name"
                @change="switchScene"
            >
                <option value="" disabled>Select scene...</option>
                <option v-for="s in sceneStore.sceneList" :key="s" :value="s">
                    {{ s }}
                </option>
            </select>
        </template>
        <span v-else class="text-xs text-editor-muted">
            {{ sceneStore.name || 'No scene' }}
        </span>

        <span
            v-if="sceneStore.dirty"
            class="w-2 h-2 rounded-full bg-editor-accent shrink-0"
            title="Unsaved changes"
        />

        <div class="flex-1" />

        <span class="text-xs text-editor-muted">PHPolygon Editor</span>
    </div>
</template>
