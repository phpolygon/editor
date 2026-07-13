<script setup lang="ts">
import { onMounted, ref } from 'vue';
import UiPreviewNode from './UiPreviewNode.vue';
import WidgetCanvas from './WidgetCanvas.vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { useUiEditorStore } from '@/stores/uiEditor';
import { useToast } from '@/composables/useToast';

const store = useUiEditorStore();
const { addToast } = useToast();

// 'preview' = WYSIWYG engine render (unfilled); 'tree' = editable node tree.
const viewMode = ref<'preview' | 'tree'>('preview');

onMounted(() => {
    store.fetchLayoutList();
    store.fetchWidgetTypes();
});

async function onSelectLayout(e: Event) {
    const name = (e.target as HTMLSelectElement).value;
    if (!name || name === store.name) return;
    if (store.dirty && !confirm('Unsaved changes will be lost. Continue?')) {
        (e.target as HTMLSelectElement).value = store.name;
        return;
    }
    try {
        await store.load(name);
    } catch (err: any) {
        addToast(err?.message ?? 'Failed to load layout', 'error');
    }
}

async function newLayout() {
    const name = window.prompt('New UI layout name:');
    if (!name) return;
    try {
        await store.create(name);
        addToast(`Created "${name}"`, 'success');
    } catch (err: any) {
        addToast(err?.message ?? 'Failed to create layout', 'error');
    }
}

async function save() {
    try {
        await store.save();
        addToast('UI layout saved', 'success');
    } catch (err: any) {
        addToast(err?.message ?? 'Save failed', 'error');
    }
}

async function exportPhp() {
    try {
        const { className } = await store.transpileToPhp();
        addToast(`Transpiled to ${className}.php`, 'success');
    } catch (err: any) {
        addToast(err?.message ?? 'Transpile failed', 'error');
    }
}
</script>

<template>
    <div class="flex flex-col h-full bg-editor-panel">
        <PanelHeader :title="store.name ? `UI: ${store.name}` : 'UI Layout'">
            <template #actions>
                <div class="flex rounded overflow-hidden border border-editor-border mr-1">
                    <button
                        class="px-1.5 py-0.5 text-[11px]"
                        :class="viewMode === 'preview' ? 'bg-editor-accent text-white' : 'hover:bg-editor-hover'"
                        title="WYSIWYG engine render (unfilled)"
                        @click="viewMode = 'preview'"
                    >
                        Preview
                    </button>
                    <button
                        class="px-1.5 py-0.5 text-[11px]"
                        :class="viewMode === 'tree' ? 'bg-editor-accent text-white' : 'hover:bg-editor-hover'"
                        title="Editable node tree"
                        @click="viewMode = 'tree'"
                    >
                        Tree
                    </button>
                </div>
                <select
                    class="bg-editor-input border border-editor-border text-editor-text text-[11px] rounded px-1 py-0.5 focus:border-editor-accent focus:outline-none"
                    :value="store.name"
                    @change="onSelectLayout"
                >
                    <option value="" disabled>Select layout…</option>
                    <option v-for="l in store.layoutList" :key="l" :value="l">{{ l }}</option>
                </select>
                <button class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover" @click="newLayout">New</button>
                <button
                    class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                    :disabled="!store.opened"
                    @click="save"
                >
                    Save
                </button>
                <button
                    class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover disabled:opacity-40"
                    :disabled="!store.opened"
                    title="Transpile this layout to a PHP WidgetTree class"
                    @click="exportPhp"
                >
                    Export PHP
                </button>
                <span v-if="store.dirty" class="w-2 h-2 rounded-full bg-editor-accent" title="Unsaved changes" />
            </template>
        </PanelHeader>

        <div class="flex-1 overflow-auto p-4" @click="store.select(store.root?._id ?? null)">
            <template v-if="store.root">
                <WidgetCanvas v-if="viewMode === 'preview'" />
                <div v-else class="inline-block min-w-[240px]">
                    <UiPreviewNode :node="store.root" />
                </div>
            </template>
            <div v-else class="h-full flex flex-col items-center justify-center gap-1">
                <span class="text-xs text-editor-muted">No UI layout loaded</span>
                <span class="text-[10px] text-editor-muted">Pick one above or create a new layout</span>
            </div>
        </div>
    </div>
</template>
