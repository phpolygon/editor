<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import EntityLink from '@/components/ui/EntityLink.vue';
import ShaderPreviewViewport from './ShaderPreviewViewport.vue';
import { useShaderEditorStore } from '@/stores/shaderEditor';
import { useToast } from '@/composables/useToast';

const store = useShaderEditorStore();
const { addToast } = useToast();

/**
 * Save the shader and point the linked entity's material at it. The reference
 * lives in the material, so this rewrites that material rather than the entity.
 */
async function applyToEntity() {
    const entity = store.linkedEntity?.entity ?? '';
    try {
        const r = await store.applyToEntity();
        addToast(`Material “${r.materialId}” of ${entity} now uses shader “${r.shader}”`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to apply the shader', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Shader Preview">
            <template #actions>
                <EntityLink
                    v-if="store.linkedEntity"
                    :entity="store.linkedEntity.entity"
                    :hint="`Editing the shader of material “${store.linkedEntity.materialId}”`"
                    apply-label="Apply to Material"
                    @apply="applyToEntity"
                    @unlink="store.clearEntityLink()"
                />
            </template>
        </PanelHeader>
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
