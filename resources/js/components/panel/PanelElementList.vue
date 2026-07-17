<script setup lang="ts">
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { usePanelEditorStore } from '@/stores/panelEditor';
import { useToast } from '@/composables/useToast';
import { useDialog } from '@/composables/useDialog';

const store = usePanelEditorStore();
const { addToast } = useToast();
const { prompt } = useDialog();

async function add() {
    const id = await prompt({ title: 'Add element', message: 'Element id:', placeholder: 'play_button' });
    if (!id) return;
    try {
        await store.addElement(id.trim());
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to add element', 'error');
    }
}

async function rename(oldId: string) {
    const next = await prompt({ title: 'Rename element', value: oldId });
    if (!next || next === oldId) return;
    try {
        await store.renameElement(oldId, next.trim());
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to rename', 'error');
    }
}

async function removeSelected() {
    const id = store.selectedId;
    if (!id) return;
    try {
        await store.removeElement(id);
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to remove', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Elements">
            <template #actions>
                <button
                    class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                    :disabled="!store.opened"
                    @click="add"
                >
                    + Add
                </button>
                <button
                    class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                    :disabled="!store.selectedId"
                    @click="removeSelected"
                >
                    Del
                </button>
            </template>
        </PanelHeader>

        <div class="flex-1 overflow-auto">
            <div
                v-for="id in store.elementIds"
                :key="id"
                class="flex items-center gap-1 px-2 py-0.5 text-xs cursor-pointer hover:bg-editor-hover"
                :class="store.selectedId === id ? 'bg-editor-active' : ''"
                @click="store.select(id)"
                @dblclick="rename(id)"
            >
                <span class="truncate">{{ id }}</span>
            </div>
            <div v-if="store.opened && store.elementIds.length === 0" class="p-3 text-[10px] text-editor-muted">
                No elements — use “+ Add”
            </div>
            <div v-else-if="!store.opened" class="p-3 text-xs text-editor-muted">No layout loaded</div>
        </div>
    </div>
</template>
