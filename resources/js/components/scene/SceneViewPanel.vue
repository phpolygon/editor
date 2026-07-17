<script setup lang="ts">
import { Clapperboard } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import SceneViewport3D from '@/components/scene/SceneViewport3D.vue';
import SceneViewport2D from '@/components/scene/SceneViewport2D.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useSceneStore } from '@/stores/scene';

const sceneStore = useSceneStore();
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <!-- Play lives in the toolbar now; the viewport header just names the scene. -->
        <PanelHeader :title="sceneStore.name ? `Scene: ${sceneStore.name}` : 'Scene View'" />

        <div class="flex-1 relative">
            <EmptyState
                v-if="!sceneStore.name"
                :icon="Clapperboard"
                title="No scene loaded"
                hint="Pick a scene from the toolbar dropdown, or create a new one with “New Scene”."
                class="absolute inset-0"
            />

            <SceneViewport3D v-else-if="sceneStore.mode === '3d'" class="absolute inset-0" />
            <SceneViewport2D v-else class="absolute inset-0" />
        </div>
    </div>
</template>
