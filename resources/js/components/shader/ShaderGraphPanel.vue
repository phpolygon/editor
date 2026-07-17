<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import ShaderGraphCanvas from './ShaderGraphCanvas.vue';
import { useShaderEditorStore } from '@/stores/shaderEditor';

const store = useShaderEditorStore();
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Shader Graph" />
        <ShaderGraphCanvas
            class="flex-1 min-h-0"
            :model-value="store.graph"
            @update:model-value="store.setGraph"
        />
    </div>
</template>
