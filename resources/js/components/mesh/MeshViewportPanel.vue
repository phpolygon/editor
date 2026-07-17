<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { Save, Pencil, Check, FlipVertical2 } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import MeshPreviewViewport from './MeshPreviewViewport.vue';
import { useMeshEditorStore } from '@/stores/meshEditor';
import { useToast } from '@/composables/useToast';

const store = useMeshEditorStore();
const { addToast } = useToast();

// Re-evaluate the graph shortly after any edit (debounced). Skipped in edit
// mode, where the baked raw mesh — not the graph — is the source of truth.
let timer: number | undefined;
function scheduleEval() {
    if (store.editMode) return;
    if (timer) clearTimeout(timer);
    timer = window.setTimeout(() => store.evaluate(), 150);
}

watch(() => store.graph, scheduleEval);
onMounted(() => store.evaluate());

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
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Mesh Preview">
            <template #actions>
                <span class="text-[11px] text-editor-muted tabular-nums mr-1">{{ stats }}</span>

                <template v-if="store.editMode">
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

                <input
                    v-model="store.name"
                    type="text"
                    placeholder="mesh name"
                    class="h-7 w-28 px-2 rounded-md bg-editor-input border border-editor-border text-xs
                           focus:outline-none focus:border-editor-accent"
                />
                <Button :icon="Save" @click="save">Save</Button>
            </template>
        </PanelHeader>

        <div class="flex-1 relative">
            <MeshPreviewViewport
                class="absolute inset-0"
                :mesh="store.preview"
                :edit-mode="store.editMode"
                :edit-mesh="store.editedMesh"
                @update:vertices="store.updateEditedVertices"
            />
            <div
                v-if="store.editMode"
                class="absolute top-2 left-2 px-2.5 py-1 rounded-md bg-editor-elevated/90 border border-editor-border text-[11px] text-editor-muted"
            >
                Click a vertex handle, drag to move. Coincident vertices move together.
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
