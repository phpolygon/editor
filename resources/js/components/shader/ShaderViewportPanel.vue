<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import ShaderPreviewViewport from './ShaderPreviewViewport.vue';
import { useShaderEditorStore } from '@/stores/shaderEditor';

const store = useShaderEditorStore();
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Shader Preview" />
        <div class="flex-1 relative">
            <ShaderPreviewViewport
                class="absolute inset-0"
                :graph="store.graph"
                @error="store.error = $event"
            />
            <div
                v-if="store.error"
                class="absolute bottom-2 left-2 right-2 px-3 py-2 rounded-md bg-editor-danger/90 text-white text-xs"
            >
                {{ store.error }}
            </div>
        </div>
    </div>
</template>
