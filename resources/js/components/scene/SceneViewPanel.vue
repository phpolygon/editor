<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import SceneViewport3D from '@/components/scene/SceneViewport3D.vue';
import SceneViewport2D from '@/components/scene/SceneViewport2D.vue';
import { useSceneStore } from '@/stores/scene';
import { useEditorStore } from '@/stores/editor';
import { useProjectStore } from '@/stores/project';

const sceneStore = useSceneStore();
const editorStore = useEditorStore();
const projectStore = useProjectStore();

function togglePlay() {
    if (editorStore.playing) {
        editorStore.stop();
    } else {
        editorStore.play();
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader :title="sceneStore.name ? `Scene: ${sceneStore.name}` : 'Scene View'">
            <template #actions>
                <button
                    v-if="sceneStore.name"
                    class="px-3 py-0.5 text-[11px] font-medium rounded"
                    :class="editorStore.playing
                        ? 'bg-red-600 hover:bg-red-700 text-white'
                        : 'bg-green-600 hover:bg-green-700 text-white'"
                    @click="togglePlay"
                >
                    {{ editorStore.playing ? 'Stop' : 'Play' }}
                </button>
            </template>
        </PanelHeader>

        <div class="flex-1 relative">
            <template v-if="!projectStore.opened">
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1">
                    <span class="text-xs text-editor-muted">No project opened</span>
                    <span class="text-[10px] text-editor-muted">Use "Open Project" in the toolbar</span>
                </div>
            </template>

            <template v-else-if="!sceneStore.name">
                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1">
                    <span class="text-xs text-editor-muted">No scene loaded</span>
                    <span class="text-[10px] text-editor-muted">Select a scene from the toolbar dropdown</span>
                </div>
            </template>

            <SceneViewport3D v-else-if="sceneStore.mode === '3d'" class="absolute inset-0" />
            <SceneViewport2D v-else class="absolute inset-0" />
        </div>
    </div>
</template>
