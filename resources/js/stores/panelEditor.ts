import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import * as commands from '@/bridge/commands';
import type { PanelElement, PanelLayoutData } from '@/bridge/commands';

export const usePanelEditorStore = defineStore('panelEditor', () => {
    const name = ref('');
    const elements = ref<Record<string, PanelElement>>({});
    const layoutList = ref<string[]>([]);
    const selectedId = ref<string | null>(null);
    const loading = ref(false);
    const dirty = ref(false);

    const opened = computed(() => name.value !== '');
    const elementIds = computed(() => Object.keys(elements.value));
    const selectedElement = computed(() =>
        selectedId.value ? elements.value[selectedId.value] ?? null : null,
    );

    function apply(data: PanelLayoutData) {
        name.value = data.name;
        elements.value = data.elements ?? {};
    }

    async function fetchLayoutList() {
        try {
            layoutList.value = (await commands.listPanelLayouts()).layouts;
        } catch {
            layoutList.value = [];
        }
    }

    async function create(layoutName: string) {
        loading.value = true;
        try {
            apply(await commands.createPanelLayout(layoutName));
            selectedId.value = null;
            dirty.value = false;
            await fetchLayoutList();
        } finally {
            loading.value = false;
        }
    }

    async function load(layoutName: string) {
        loading.value = true;
        try {
            apply(await commands.loadPanelLayout(layoutName));
            selectedId.value = null;
            dirty.value = false;
        } finally {
            loading.value = false;
        }
    }

    async function save() {
        await commands.savePanelLayout();
        dirty.value = false;
    }

    async function addElement(id: string) {
        const result = await commands.addLayoutElement(id);
        apply(result);
        selectedId.value = result.added;
        dirty.value = true;
    }

    async function removeElement(id: string) {
        apply(await commands.removeLayoutElement(id));
        if (selectedId.value === id) selectedId.value = null;
        dirty.value = true;
    }

    async function renameElement(oldId: string, newId: string) {
        apply(await commands.renameLayoutElement(oldId, newId));
        selectedId.value = newId;
        dirty.value = true;
    }

    async function updateElement(id: string, props: Record<string, unknown>) {
        apply(await commands.updateLayoutElement(id, props));
        dirty.value = true;
    }

    /** Local-only rect update during a drag (no round-trip); commit on release. */
    function setRectLocal(id: string, rect: Partial<PanelElement>) {
        const el = elements.value[id];
        if (el) elements.value[id] = { ...el, ...rect };
    }

    function select(id: string | null) {
        selectedId.value = id;
    }

    return {
        name,
        elements,
        layoutList,
        selectedId,
        loading,
        dirty,
        opened,
        elementIds,
        selectedElement,
        fetchLayoutList,
        create,
        load,
        save,
        addElement,
        removeElement,
        renameElement,
        updateElement,
        setRectLocal,
        select,
    };
});
