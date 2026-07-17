<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import GraphCanvas from './GraphCanvas.vue';
import { useMeshEditorStore } from '@/stores/meshEditor';

const store = useMeshEditorStore();
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Node Graph" />
        <GraphCanvas
            class="flex-1 min-h-0"
            :model-value="store.graph"
            @update:model-value="store.setGraph"
        />
    </div>
</template>
