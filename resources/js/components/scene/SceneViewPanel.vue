<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
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
        <PanelHeader :title="sceneStore.name ? `Scene: ${sceneStore.name}` : 'Scene View'" />

        <div class="flex-1 flex flex-col items-center justify-center gap-3">
            <template v-if="!projectStore.opened">
                <span class="text-xs text-editor-muted">No project opened</span>
                <span class="text-[10px] text-editor-muted">Use "Open Project" in the toolbar</span>
            </template>

            <template v-else-if="!sceneStore.name">
                <span class="text-xs text-editor-muted">No scene loaded</span>
                <span class="text-[10px] text-editor-muted">Select a scene from the toolbar dropdown</span>
            </template>

            <template v-else>
                <div class="text-center space-y-1">
                    <span class="text-xs text-editor-muted block">
                        {{ sceneStore.entityCount }} {{ sceneStore.entityCount === 1 ? 'entity' : 'entities' }}
                    </span>
                    <span class="text-[10px] text-editor-muted block">
                        Scene viewport coming soon
                    </span>
                </div>

                <button
                    class="px-4 py-1.5 text-xs font-medium rounded"
                    :class="editorStore.playing
                        ? 'bg-red-600 hover:bg-red-700 text-white'
                        : 'bg-green-600 hover:bg-green-700 text-white'"
                    @click="togglePlay"
                >
                    {{ editorStore.playing ? 'Stop' : 'Play' }}
                </button>
            </template>
        </div>
    </div>
</template>
