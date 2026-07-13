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
const recentMenuOpen = ref(false);

// Delay closing dropdowns on blur so a click on a menu item registers first.
// (setTimeout isn't available inside Vue template expressions.)
function closeRecentSoon() {
    window.setTimeout(() => (recentMenuOpen.value = false), 120);
}
function closeCreateSoon() {
    window.setTimeout(() => (createMenuOpen.value = false), 120);
}

async function toggleRecentMenu() {
    if (!recentMenuOpen.value) {
        try {
            await projectStore.fetchRecent();
        } catch {
            // Non-fatal — just show whatever is cached.
        }
    }
    recentMenuOpen.value = !recentMenuOpen.value;
}

async function openRecent(dir: string, name: string) {
    recentMenuOpen.value = false;
    try {
        await projectStore.openRecent(dir);
        addToast(`Project "${projectStore.name}" opened`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? `Failed to open "${name}"`, 'error');
    }
}

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

async function addSprite() {
    createMenuOpen.value = false;
    try {
        const name = await sceneStore.createSprite(selectionStore.selectedEntity ?? null);
        selectionStore.selectEntity(name);
        addToast('Added sprite', 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to add sprite', 'error');
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

async function importProject() {
    try {
        const { created } = await projectStore.importProjectWithDialog();
        addToast(
            created
                ? `Project "${projectStore.name}" imported`
                : `Project "${projectStore.name}" opened`,
            'success',
        );
    } catch (e: any) {
        if (e?.message !== 'No directory selected') {
            addToast(e?.message ?? 'Failed to import project', 'error');
        }
    }
}

async function newScene() {
    const name = window.prompt('New scene name (e.g. main_level):');
    if (!name) return;
    try {
        await sceneStore.createScene(name);
        addToast(`Scene "${sceneStore.name}" created`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to create scene', 'error');
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

        <!-- Import Project -->
        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active"
            title="Create the editor manifest for an existing PHPolygon project folder"
            @click="importProject"
        >
            Import Project
        </button>

        <!-- Recent Projects dropdown -->
        <div class="relative">
            <button
                class="px-1.5 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active"
                title="Recent projects"
                @click="toggleRecentMenu"
                @blur="closeRecentSoon"
            >
                Recent ▾
            </button>
            <div
                v-if="recentMenuOpen"
                class="absolute left-0 top-full mt-1 z-50 bg-editor-panel border border-editor-border rounded shadow-lg min-w-[240px] max-w-[420px] py-1"
            >
                <div
                    v-if="projectStore.recent.length === 0"
                    class="px-3 py-2 text-xs text-editor-muted"
                >
                    No recent projects
                </div>
                <button
                    v-for="r in projectStore.recent"
                    :key="r.dir"
                    class="w-full text-left px-3 py-1 hover:bg-editor-hover"
                    :title="r.dir"
                    @mousedown.prevent="openRecent(r.dir, r.name)"
                >
                    <div class="text-xs truncate">{{ r.name }}</div>
                    <div class="text-[10px] text-editor-muted truncate" dir="rtl">{{ r.dir }}</div>
                </button>
            </div>
        </div>

        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- Workspace: Scene / UI -->
        <div class="inline-flex rounded border border-editor-border overflow-hidden">
            <button
                class="px-2 py-0.5 text-xs"
                :class="editorStore.workspace === 'scene' ? 'bg-editor-accent text-white' : 'hover:bg-editor-hover'"
                @click="editorStore.setWorkspace('scene')"
            >
                Scene
            </button>
            <button
                class="px-2 py-0.5 text-xs"
                :class="editorStore.workspace === 'ui' ? 'bg-editor-accent text-white' : 'hover:bg-editor-hover'"
                @click="editorStore.setWorkspace('ui')"
            >
                UI
            </button>
            <button
                class="px-2 py-0.5 text-xs"
                :class="editorStore.workspace === 'panel' ? 'bg-editor-accent text-white' : 'hover:bg-editor-hover'"
                @click="editorStore.setWorkspace('panel')"
            >
                Panels
            </button>
        </div>

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

        <template v-if="editorStore.workspace === 'scene'">
        <div class="w-px h-5 bg-editor-border mx-1" />

        <!-- New Scene -->
        <button
            class="px-2 py-1 text-xs rounded hover:bg-editor-hover active:bg-editor-active disabled:opacity-40"
            :disabled="!projectStore.opened"
            title="Create a new scene (authored in the editor, saved as PHP)"
            @click="newScene"
        >
            New Scene
        </button>

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
                @blur="closeCreateSoon"
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

                <!-- 2D content -->
                <template v-if="sceneStore.mode === '2d'">
                    <div class="border-t border-editor-border my-1" />
                    <div class="px-3 py-0.5 text-[10px] text-editor-muted uppercase tracking-wide">2D</div>
                    <button
                        class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                        @mousedown.prevent="addSprite"
                    >
                        Sprite
                    </button>
                </template>

                <!-- 3D primitives -->
                <template v-else>
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
                </template>
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
        </template>

        <div class="flex-1" />

        <span class="text-xs text-editor-muted">PHPolygon Editor</span>
    </div>
</template>
