<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import MeshPreviewViewport from './MeshPreviewViewport.vue';
import { useMeshEditorStore } from '@/stores/meshEditor';

const store = useMeshEditorStore();

// Re-evaluate the graph shortly after any edit (debounced so dragging a
// slider doesn't fire a request per value).
let timer: number | undefined;
function scheduleEval() {
    if (timer) clearTimeout(timer);
    timer = window.setTimeout(() => store.evaluate(), 150);
}

watch(() => store.graph, scheduleEval);
onMounted(() => store.evaluate());

const stats = computed(() =>
    store.preview ? `${store.preview.vertexCount} verts · ${store.preview.triangleCount} tris` : '',
);
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Mesh Preview">
            <template #actions>
                <span class="text-[11px] text-editor-muted tabular-nums">{{ stats }}</span>
            </template>
        </PanelHeader>
        <div class="flex-1 relative">
            <MeshPreviewViewport :mesh="store.preview" class="absolute inset-0" />
            <div
                v-if="store.error"
                class="absolute bottom-2 left-2 right-2 px-3 py-2 rounded-md bg-editor-danger/90 text-white text-xs"
            >
                {{ store.error }}
            </div>
        </div>
    </div>
</template>
