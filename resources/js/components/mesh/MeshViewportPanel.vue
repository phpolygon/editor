<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Save, SaveAll, Pencil, Check, FlipVertical2, FileBox, FilePlus2, Move3d, Rotate3d, Scale3d } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import MeshPreviewViewport from './MeshPreviewViewport.vue';
import { useMeshEditorStore } from '@/stores/meshEditor';
import { useToast } from '@/composables/useToast';
import { useDialog } from '@/composables/useDialog';

const store = useMeshEditorStore();
const { addToast } = useToast();
const dialog = useDialog();

type TransformMode = 'translate' | 'rotate' | 'scale';
const transformMode = ref<TransformMode>('translate');
const selectionCount = ref(0);

const transformModes = [
    { value: 'translate' as const, icon: Move3d, title: 'Move (W)' },
    { value: 'rotate' as const, icon: Rotate3d, title: 'Rotate (E)' },
    { value: 'scale' as const, icon: Scale3d, title: 'Scale (R)' },
];

// W/E/R switch the gizmo mode while editing (only when not typing in a field).
function onKey(e: KeyboardEvent) {
    if (!store.editMode) return;
    const t = e.target as HTMLElement | null;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
    if (e.key === 'w' || e.key === 'W') transformMode.value = 'translate';
    else if (e.key === 'e' || e.key === 'E') transformMode.value = 'rotate';
    else if (e.key === 'r' || e.key === 'R') transformMode.value = 'scale';
}

// Re-evaluate the graph shortly after any edit (debounced). Skipped in edit
// mode, where the baked raw mesh — not the graph — is the source of truth.
let timer: number | undefined;
function scheduleEval() {
    if (store.editMode) return;
    if (timer) clearTimeout(timer);
    timer = window.setTimeout(() => store.evaluate(), 150);
}

watch(() => store.graph, scheduleEval);
onMounted(() => {
    store.evaluate();
    window.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const stats = computed(() => {
    if (store.editMode && store.editedMesh) {
        const v = store.editedMesh.vertices.length / 3;
        const t = store.editedMesh.indices.length / 3;
        return `${v} verts · ${t} tris`;
    }
    return store.preview ? `${store.preview.vertexCount} verts · ${store.preview.triangleCount} tris` : '';
});

async function save() {
    try {
        const r = await store.saveCurrent();
        addToast(`Saved ${r.relativePath}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save mesh', 'error');
    }
}

async function saveAs() {
    const next = await dialog.prompt({
        title: 'Save mesh as',
        value: store.name,
        placeholder: 'mesh name',
        confirmLabel: 'Save',
    });
    if (next === null || next.trim() === '') return;
    try {
        const r = await store.saveAs(next.trim());
        addToast(`Saved ${r.relativePath}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save mesh', 'error');
    }
}

function newMesh() {
    store.reset();
    store.evaluate();
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Mesh Preview">
            <template #actions>
                <span class="text-[11px] text-editor-muted tabular-nums mr-1">{{ stats }}</span>

                <!-- What's currently open: a saved asset, or an unsaved draft. -->
                <span
                    class="flex items-center gap-1.5 h-7 px-2 rounded-md bg-editor-input border border-editor-border text-xs max-w-[11rem]"
                    :title="store.loadedAssetName ? `Editing saved mesh “${store.loadedAssetName}”` : 'Unsaved mesh'"
                >
                    <FileBox :size="13" :stroke-width="2" class="shrink-0" :class="store.loadedAssetName ? 'text-editor-accent' : 'text-editor-muted'" />
                    <span class="truncate">{{ store.loadedAssetName ?? store.name }}</span>
                    <span v-if="!store.loadedAssetName" class="text-editor-muted">· unsaved</span>
                </span>

                <template v-if="store.editMode">
                    <SegmentedControl v-model="transformMode" :options="transformModes" />
                    <span class="text-[11px] text-editor-muted tabular-nums mr-1 w-16 text-right">
                        {{ selectionCount }} sel.
                    </span>
                    <Button :icon="FlipVertical2" title="Flip normals" @click="store.flipEditedNormals()">Flip</Button>
                    <Button :icon="Check" @click="store.exitEditMode()">Done</Button>
                </template>
                <Button
                    v-else
                    :icon="Pencil"
                    :disabled="!store.preview"
                    title="Bake the current mesh and edit its vertices"
                    @click="store.enterEditMode()"
                >
                    Edit Vertices
                </Button>

                <Button :icon="FilePlus2" title="Start a new mesh" @click="newMesh">New</Button>
                <Button
                    :icon="Save"
                    :title="store.loadedAssetName ? `Overwrite “${store.loadedAssetName}”` : 'Save mesh'"
                    @click="save"
                >
                    Save
                </Button>
                <Button :icon="SaveAll" title="Save under a new name" @click="saveAs">Save As…</Button>
            </template>
        </PanelHeader>

        <div class="flex-1 relative">
            <MeshPreviewViewport
                class="absolute inset-0"
                :mesh="store.preview"
                :edit-mode="store.editMode"
                :edit-mesh="store.editedMesh"
                :transform-mode="transformMode"
                @update:vertices="store.updateEditedVertices"
                @update:selection-count="selectionCount = $event"
            />
            <div
                v-if="store.editMode"
                class="absolute top-2 left-2 px-2.5 py-1 rounded-md bg-editor-elevated/90 border border-editor-border text-[11px] text-editor-muted max-w-[22rem] leading-relaxed"
            >
                Click a handle to select · Shift-click to add · Shift-drag for a box.
                Drag the gizmo to move/rotate/scale — W/E/R switch modes.
            </div>
            <div
                v-if="store.error"
                class="absolute bottom-2 left-2 right-2 px-3 py-2 rounded-md bg-editor-danger/90 text-white text-xs"
            >
                {{ store.error }}
            </div>
        </div>
    </div>
</template>
