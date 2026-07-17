<script setup lang="ts">
import { computed } from 'vue';
import {
    FolderOpen,
    FolderInput,
    History,
    Play,
    Square,
    RotateCw,
    FilePlus2,
    Save,
    Undo2,
    Redo2,
    Plus,
    Package,
    Box,
    Circle,
    Cylinder,
    Square as SquareIcon,
    Image,
    SquareDashed,
} from 'lucide-vue-next';
import { useSceneStore } from '@/stores/scene';
import { useEditorStore } from '@/stores/editor';
import { useProjectStore } from '@/stores/project';
import { useSelectionStore } from '@/stores/selection';
import { useToast } from '@/composables/useToast';
import { useDialog } from '@/composables/useDialog';
import { WORKSPACES } from '@/workspaces';
import Button from '@/components/ui/Button.vue';
import IconButton from '@/components/ui/IconButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Select from '@/components/ui/Select.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import MenuSeparator from '@/components/ui/MenuSeparator.vue';
import ToolbarGroup from '@/components/ui/ToolbarGroup.vue';
import type { PrimitiveType } from '@/bridge/commands';

const sceneStore = useSceneStore();
const editorStore = useEditorStore();
const projectStore = useProjectStore();
const selectionStore = useSelectionStore();
const { addToast } = useToast();
const { prompt, confirm } = useDialog();

const workspaceOptions = computed(() =>
    WORKSPACES.map((w) => ({ value: w.id, label: w.label, icon: w.icon, title: w.label })),
);

const modeOptions = [
    { value: '2d' as const, label: '2D', title: '2D scene mode' },
    { value: '3d' as const, label: '3D', title: '3D scene mode' },
];

const sceneOptions = computed(() => sceneStore.sceneList.map((s) => ({ value: s, label: s })));

async function loadRecent() {
    try {
        await projectStore.fetchRecent();
    } catch {
        // Non-fatal — show whatever is cached.
    }
}

async function openRecent(dir: string, name: string) {
    try {
        await projectStore.openRecent(dir);
        addToast(`Project "${projectStore.name}" opened`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? `Failed to open "${name}"`, 'error');
    }
}

async function addPrimitive(type: PrimitiveType) {
    try {
        const name = await sceneStore.createPrimitive(type, selectionStore.selectedEntity ?? null);
        selectionStore.selectEntity(name);
        addToast(`Added ${type}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? `Failed to add ${type}`, 'error');
    }
}

async function addEmpty() {
    try {
        await sceneStore.createEntity('Entity', selectionStore.selectedEntity ?? null);
        addToast('Added empty entity', 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to add entity', 'error');
    }
}

async function addSprite() {
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
    const name = await prompt({
        title: 'Save as prefab',
        message: 'Name for the reusable prefab:',
        value: selected,
        placeholder: 'prefab_name',
    });
    if (name === null) return;
    try {
        const result = await sceneStore.savePrefab(selected, name || null);
        addToast(`Saved prefab: ${result.name}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save prefab', 'error');
    }
}

function togglePlay() {
    editorStore.playing ? editorStore.stop() : editorStore.play();
}

async function rebuild() {
    try {
        await sceneStore.reload();
        addToast('Scene rebuilt', 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Rebuild failed', 'error');
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
    const name = await prompt({
        title: 'New scene',
        message: 'Scene name (authored in the editor, saved as PHP):',
        placeholder: 'main_level',
    });
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

async function switchScene(sceneName: string) {
    if (!sceneName || sceneName === sceneStore.name) return;
    if (
        sceneStore.dirty &&
        !(await confirm({
            title: 'Unsaved changes',
            message: 'You have unsaved changes that will be lost. Continue?',
            confirmLabel: 'Discard & switch',
            danger: true,
        }))
    ) {
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
    <div class="flex items-center gap-1 px-2 h-11 bg-editor-panel border-b border-editor-border shrink-0">
        <!-- Project -->
        <ToolbarGroup>
            <Button :icon="FolderOpen" @click="openProject">Open</Button>
            <Button
                :icon="FolderInput"
                title="Create the editor manifest for an existing PHPolygon project folder"
                @click="importProject"
            >
                Import
            </Button>
            <Menu align="left" width="min-w-[260px] max-w-[440px]">
                <template #trigger="{ toggle }">
                    <IconButton
                        :icon="History"
                        label="Recent projects"
                        @click="() => { loadRecent(); toggle(); }"
                    />
                </template>
                <template #default="{ close }">
                    <MenuLabel>Recent projects</MenuLabel>
                    <div v-if="projectStore.recent.length === 0" class="px-3 py-2 text-xs text-editor-muted">
                        No recent projects
                    </div>
                    <MenuItem
                        v-for="r in projectStore.recent"
                        :key="r.dir"
                        :icon="FolderOpen"
                        @click="() => { openRecent(r.dir, r.name); close(); }"
                    >
                        <span class="block truncate">{{ r.name }}</span>
                        <span class="block text-[10px] text-editor-muted truncate" dir="rtl">{{ r.dir }}</span>
                    </MenuItem>
                </template>
            </Menu>
        </ToolbarGroup>

        <!-- Workspace switcher -->
        <ToolbarGroup>
            <SegmentedControl
                :model-value="editorStore.workspace"
                :options="workspaceOptions"
                @update:model-value="editorStore.setWorkspace"
            />
        </ToolbarGroup>

        <!-- Play + rebuild -->
        <ToolbarGroup :divider="editorStore.workspace === 'scene'">
            <Button
                :icon="editorStore.playing ? Square : Play"
                :variant="editorStore.playing ? 'danger' : 'success'"
                @click="togglePlay"
            >
                {{ editorStore.playing ? 'Stop' : 'Play' }}
            </Button>
            <IconButton
                v-if="editorStore.workspace === 'scene'"
                :icon="RotateCw"
                label="Rebuild: reload the scene from PHP (re-runs build(), re-captures meshes/materials)"
                :disabled="sceneStore.loading || !sceneStore.name"
                @click="rebuild"
            />
        </ToolbarGroup>

        <!-- Scene-only controls -->
        <template v-if="editorStore.workspace === 'scene'">
            <ToolbarGroup>
                <Button
                    :icon="FilePlus2"
                    :disabled="!projectStore.opened"
                    title="Create a new scene"
                    @click="newScene"
                >
                    New Scene
                </Button>
                <IconButton
                    :icon="Save"
                    label="Save scene (Ctrl+S)"
                    :disabled="sceneStore.loading || !sceneStore.name"
                    @click="save"
                />
                <IconButton
                    :icon="Undo2"
                    label="Undo (Ctrl+Z)"
                    :disabled="sceneStore.loading || !sceneStore.name"
                    @click="sceneStore.undoAction()"
                />
                <IconButton
                    :icon="Redo2"
                    label="Redo (Ctrl+Shift+Z)"
                    :disabled="sceneStore.loading || !sceneStore.name"
                    @click="sceneStore.redoAction()"
                />
            </ToolbarGroup>

            <!-- Scene selector -->
            <ToolbarGroup>
                <Select
                    v-if="projectStore.opened && sceneStore.sceneList.length > 0"
                    :model-value="sceneStore.name"
                    :options="sceneOptions"
                    placeholder="Select scene…"
                    @update:model-value="switchScene"
                />
                <span v-else class="text-xs text-editor-muted px-1">{{ sceneStore.name || 'No scene' }}</span>
                <span
                    v-if="sceneStore.dirty"
                    class="w-2 h-2 rounded-full bg-editor-accent shrink-0"
                    title="Unsaved changes"
                />
            </ToolbarGroup>

            <!-- Create + prefab -->
            <ToolbarGroup>
                <Menu align="left" width="min-w-[180px]">
                    <template #trigger="{ toggle }">
                        <Button :icon="Plus" :disabled="!sceneStore.name" @click="toggle">Create</Button>
                    </template>
                    <template #default="{ close }">
                        <MenuItem :icon="SquareDashed" @click="() => { addEmpty(); close(); }">
                            Empty Entity
                        </MenuItem>
                        <template v-if="sceneStore.mode === '2d'">
                            <MenuSeparator />
                            <MenuLabel>2D</MenuLabel>
                            <MenuItem :icon="Image" @click="() => { addSprite(); close(); }">Sprite</MenuItem>
                        </template>
                        <template v-else>
                            <MenuSeparator />
                            <MenuLabel>3D Primitive</MenuLabel>
                            <MenuItem :icon="Box" @click="() => { addPrimitive('box'); close(); }">Box</MenuItem>
                            <MenuItem :icon="Circle" @click="() => { addPrimitive('sphere'); close(); }">Sphere</MenuItem>
                            <MenuItem :icon="Cylinder" @click="() => { addPrimitive('cylinder'); close(); }">Cylinder</MenuItem>
                            <MenuItem :icon="SquareIcon" @click="() => { addPrimitive('plane'); close(); }">Plane</MenuItem>
                        </template>
                    </template>
                </Menu>
                <Button
                    :icon="Package"
                    :disabled="!selectionStore.selectedEntity"
                    :title="selectionStore.selectedEntity ? 'Save selection as prefab' : 'Select an entity first'"
                    @click="saveAsPrefab"
                >
                    Prefab
                </Button>
            </ToolbarGroup>

            <!-- 2D / 3D mode -->
            <ToolbarGroup :divider="false">
                <SegmentedControl
                    :model-value="sceneStore.mode"
                    :options="modeOptions"
                    :disabled="!sceneStore.name"
                    @update:model-value="sceneStore.setMode"
                />
            </ToolbarGroup>
        </template>

        <div class="flex-1" />

        <span class="text-xs font-medium text-editor-muted pr-1">PHPolygon Editor</span>
    </div>
</template>
