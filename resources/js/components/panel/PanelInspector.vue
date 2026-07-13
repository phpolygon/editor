<script setup lang="ts">
import { computed } from 'vue';
import PanelHeader from '@/components/layout/PanelHeader.vue';
import { usePanelEditorStore } from '@/stores/panelEditor';

const store = usePanelEditorStore();
const RECT = ['x', 'y', 'width', 'height'];

const el = computed(() => store.selectedElement);
const id = computed(() => store.selectedId);

const otherKeys = computed(() =>
    el.value ? Object.keys(el.value).filter((k) => !RECT.includes(k)) : [],
);

function numVal(key: string): number {
    const v = el.value?.[key];
    return typeof v === 'number' ? v : 0;
}

function setNum(key: string, e: Event) {
    if (!id.value) return;
    store.updateElement(id.value, { [key]: Math.round(parseFloat((e.target as HTMLInputElement).value) || 0) });
}

function setStr(key: string, e: Event) {
    if (!id.value) return;
    store.updateElement(id.value, { [key]: (e.target as HTMLInputElement).value });
}

function addProp() {
    if (!id.value) return;
    const key = window.prompt('Property name (e.g. label, style):');
    if (!key || RECT.includes(key)) return;
    store.updateElement(id.value, { [key.trim()]: '' });
}

const input = 'w-full min-w-0 bg-editor-input border border-editor-border text-xs rounded px-1 py-0.5 focus:border-editor-accent focus:outline-none';
</script>

<template>
    <div class="flex flex-col h-full">
        <PanelHeader :title="id ? `Element: ${id}` : 'Inspector'" />

        <div v-if="el && id" class="flex-1 overflow-auto p-2 space-y-2">
            <!-- rect -->
            <div class="grid grid-cols-2 gap-1">
                <label v-for="k in RECT" :key="k" class="flex items-center gap-1">
                    <span class="w-12 text-[11px] text-editor-muted">{{ k }}</span>
                    <input type="number" step="1" :class="input" :value="numVal(k)" @change="setNum(k, $event)" />
                </label>
            </div>

            <div class="border-t border-editor-border pt-2 flex items-center justify-between">
                <span class="text-[10px] text-editor-muted uppercase tracking-wide">Props</span>
                <button class="px-1.5 py-0.5 text-[11px] rounded hover:bg-editor-hover" @click="addProp">+ Prop</button>
            </div>

            <div v-for="k in otherKeys" :key="k" class="flex items-center gap-2">
                <label class="w-20 shrink-0 text-[11px] text-editor-muted truncate" :title="k">{{ k }}</label>
                <input :class="input" :value="String(el[k] ?? '')" @change="setStr(k, $event)" />
            </div>
        </div>
        <div v-else class="p-3 text-xs text-editor-muted">Select an element to edit it</div>
    </div>
</template>
