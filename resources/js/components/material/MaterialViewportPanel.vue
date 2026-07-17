<script setup lang="ts">
import { Save } from 'lucide-vue-next';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import Button from '@/components/ui/Button.vue';
import MaterialPreviewViewport from './MaterialPreviewViewport.vue';
import { useMaterialEditorStore } from '@/stores/materialEditor';
import { useToast } from '@/composables/useToast';

const store = useMaterialEditorStore();
const { addToast } = useToast();

async function save() {
    try {
        const r = await store.save();
        addToast(`Saved ${r.relativePath}`, 'success');
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to save material', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader title="Material Preview">
            <template #actions>
                <input
                    v-model="store.material.id"
                    type="text"
                    placeholder="material id"
                    class="h-7 w-32 px-2 rounded-md bg-editor-input border border-editor-border text-xs
                           focus:outline-none focus:border-editor-accent"
                />
                <Button :icon="Save" @click="save">Save</Button>
            </template>
        </PanelHeader>
        <div class="flex-1 relative">
            <MaterialPreviewViewport :material="store.material" class="absolute inset-0" />
        </div>
    </div>
</template>
