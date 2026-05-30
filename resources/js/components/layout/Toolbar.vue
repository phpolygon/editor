<script setup lang="ts">
import { ref } from 'vue';
import { useSceneStore } from '@/stores/scene';
import { useEditorStore } from '@/stores/editor';
import { useProjectStore } from '@/stores/project';
import { useSelectionStore } from '@/stores/selection';
import { useToast } from '@/composables/useToast';
import type { PrimitiveType } from '@/bridge/commands';

const sceneStore = useSceneStore();
const editorStore = useEditorStore();
const projectStore = useProjectStore();
const selectionStore = useSelectionStore();
const { addToast } = useToast();

const createMenuOpen = ref(false);

async function addPrimitive(type: PrimitiveType) {
    createMenuOpen.value = false;
    try {
        const name = await sceneStore.createPrimitive(type, selectionStore.selectedEntity ?? null);
        selectionStore.selectEntity(name);
        addToast(`Added ${type}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? `Failed to add ${type}`, 'error');
    }
}

async function addEmpty() {
    createMenuOpen.value = false;
    const name = 'Entity';
    try {
        await sceneStore.createEntity(name, selectionStore.selectedEntity ?? null);
        addToast('Added empty entity', 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to add entity', 'error');
    }
}

async function saveAsPrefab() {
    const selected = selectionStore.selectedEntity;
    if (!selected) return;
    const suggested = window.prompt('Prefab name:', selected);
    if (suggested === null) return;
    try {
        const result = await sceneStore.savePrefab(selected, suggested || null);
        addToast(`Saved prefab: ${result.name}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save prefab', 'error');
    }
}

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

        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- Create dropdown -->
        <div class="relative">
            <button
                class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active disabled:opacity-40"
                :disabled="!sceneStore.name"
                @click="createMenuOpen = !createMenuOpen"
                @blur="setTimeout(() => createMenuOpen = false, 120)"
            >
                + Create
            </button>
            <div
                v-if="createMenuOpen"
                class="absolute left-0 top-full mt-1 z-50 bg-editor-panel border border-editor-border rounded shadow-lg min-w-[160px] py-1"
            >
                <button
                    class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                    @mousedown.prevent="addEmpty"
                >
                    Empty Entity
                </button>
                <div class="border-t border-editor-border my-1" />
                <div class="px-3 py-0.5 text-[10px] text-editor-muted uppercase tracking-wide">3D Primitive</div>
                <button
                    class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                    @mousedown.prevent="addPrimitive('box')"
                >
                    Box
                </button>
                <button
                    class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                    @mousedown.prevent="addPrimitive('sphere')"
                >
                    Sphere
                </button>
                <button
                    class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                    @mousedown.prevent="addPrimitive('cylinder')"
                >
                    Cylinder
                </button>
                <button
                    class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                    @mousedown.prevent="addPrimitive('plane')"
                >
                    Plane
                </button>
            </div>
        </div>

        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active disabled:opacity-40"
            :disabled="!selectionStore.selectedEntity"
            :title="selectionStore.selectedEntity ? 'Save selection as prefab' : 'Select an entity first'"
            @click="saveAsPrefab"
        >
            Save Prefab
        </button>

        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- 2D/3D mode toggle -->
        <div class="inline-flex rounded border border-editor-border overflow-hidden">
            <button
                class="px-2 py-0.5 text-xs"
                :class="sceneStore.mode === '2d'
                    ? 'bg-editor-accent text-white'
                    : 'hover:bg-editor-hover'"
                :disabled="!sceneStore.name"
                @click="sceneStore.setMode('2d')"
            >
                2D
            </button>
            <button
                class="px-2 py-0.5 text-xs"
                :class="sceneStore.mode === '3d'
                    ? 'bg-editor-accent text-white'
                    : 'hover:bg-editor-hover'"
                :disabled="!sceneStore.name"
                @click="sceneStore.setMode('3d')"
            >
                3D
            </button>
        </div>

        <div class="flex-1" />

        <span class="text-xs text-editor-muted">PHPolygon Editor</span>
    </div>
</template>
