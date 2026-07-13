<script setup lang="ts">
import { onMounted, ref } from 'vue';
import UiTreeNode from './UiTreeNode.vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { useUiEditorStore } from '@/stores/uiEditor';
import { useToast } from '@/composables/useToast';

const store = useUiEditorStore();
const { addToast } = useToast();
const addMenuOpen = ref(false);

onMounted(() => {
    store.fetchWidgetTypes();
});

function closeAddMenuSoon() {
    // Delay so a click on a menu item registers before the menu closes.
    window.setTimeout(() => {
        addMenuOpen.value = false;
    }, 120);
}

async function add(type: string) {
    addMenuOpen.value = false;
    // Add under the selected container, else under the root.
    const sel = store.selectedWidget;
    const parentId = sel && store.isContainer(sel._widget) ? sel._id : store.root?._id;
    if (!parentId) return;
    try {
        await store.addWidget(parentId, type);
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to add widget', 'error');
    }
}

async function removeSelected() {
    const id = store.selectedId;
    if (!id || id === store.root?._id) return;
    try {
        await store.removeWidget(id);
    } catch (e: any) {
        addToast(e?.message ?? 'Failed to remove widget', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader title="Widgets">
            <template #actions>
                <div class="relative">
                    <button
                        class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                        :disabled="!store.opened"
                        @click="addMenuOpen = !addMenuOpen"
                        @blur="closeAddMenuSoon"
                    >
                        + Add
                    </button>
                    <div
                        v-if="addMenuOpen"
                        class="absolute right-0 top-full mt-1 z-50 bg-editor-panel border border-editor-border rounded shadow-lg py-1 min-w-[130px]"
                    >
                        <button
                            v-for="t in store.widgetTypes"
                            :key="t.type"
                            class="w-full text-left px-3 py-1 text-xs hover:bg-editor-hover"
                            @mousedown.prevent="add(t.type)"
                        >
                            {{ t.type }}
                            <span v-if="t.container" class="text-[9px] text-editor-muted">container</span>
                        </button>
                    </div>
                </div>
                <button
                    class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                    :disabled="!store.selectedId || store.selectedId === store.root?._id"
                    @click="removeSelected"
                >
                    Del
                </button>
            </template>
        </PanelHeader>

        <div class="flex-1 overflow-auto">
            <UiTreeNode v-if="store.root" :node="store.root" :depth="0" />
            <div v-else class="p-3 text-xs text-editor-muted">No UI layout loaded</div>
        </div>
    </div>
</template>
