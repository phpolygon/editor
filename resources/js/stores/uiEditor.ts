import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import * as commands from '@/bridge/commands';
import type { UiLayoutData, WidgetField, WidgetNode, WidgetType } from '@/bridge/commands';

function findNode(node: WidgetNode | null, id: string): WidgetNode | null {
    if (!node) return null;
    if (node._id === id) return node;
    for (const child of node.children ?? []) {
        const found = findNode(child, id);
        if (found) return found;
    }
    return null;
}

function findParent(node: WidgetNode | null, id: string): WidgetNode | null {
    if (!node) return null;
    for (const child of node.children ?? []) {
        if (child._id === id) return node;
        const found = findParent(child, id);
        if (found) return found;
    }
    return null;
}

function isDescendant(node: WidgetNode | null, ancestorId: string, id: string): boolean {
    const ancestor = findNode(node, ancestorId);
    return ancestor ? findNode(ancestor, id) !== null : false;
}

export const useUiEditorStore = defineStore('uiEditor', () => {
    const name = ref('');
    const root = ref<WidgetNode | null>(null);
    const layoutList = ref<string[]>([]);
    const widgetTypes = ref<WidgetType[]>([]);
    const selectedId = ref<string | null>(null);
    const loading = ref(false);
    const dirty = ref(false);

    const opened = computed(() => root.value !== null);
    const selectedWidget = computed(() =>
        selectedId.value ? findNode(root.value, selectedId.value) : null,
    );

    function apply(data: UiLayoutData) {
        name.value = data.name;
        root.value = data.root;
    }

    async function fetchLayoutList() {
        try {
            layoutList.value = (await commands.listUiLayouts()).layouts;
        } catch {
            layoutList.value = [];
        }
    }

    async function fetchWidgetTypes() {
        if (widgetTypes.value.length > 0) return;
        try {
            widgetTypes.value = (await commands.listWidgetTypes()).types;
        } catch {
            widgetTypes.value = [];
        }
    }

    async function create(layoutName: string, rootType = 'VBox') {
        loading.value = true;
        try {
            apply(await commands.createUiLayout(layoutName, rootType));
            selectedId.value = root.value?._id ?? null;
            dirty.value = false;
            await fetchLayoutList();
        } finally {
            loading.value = false;
        }
    }

    async function load(layoutName: string) {
        loading.value = true;
        try {
            apply(await commands.loadUiLayout(layoutName));
            selectedId.value = root.value?._id ?? null;
            dirty.value = false;
        } finally {
            loading.value = false;
        }
    }

    async function save() {
        await commands.saveUiLayout();
        dirty.value = false;
    }

    async function transpileToPhp(): Promise<{ path: string; className: string }> {
        return await commands.transpileUiLayout();
    }

    async function addWidget(parentId: string, type: string) {
        const result = await commands.addWidget(parentId, type);
        apply(result);
        selectedId.value = result.added;
        dirty.value = true;
    }

    async function removeWidget(id: string) {
        apply(await commands.removeWidget(id));
        if (selectedId.value === id) selectedId.value = root.value?._id ?? null;
        dirty.value = true;
    }

    async function reparentWidget(id: string, newParentId: string, index: number | null = null) {
        apply(await commands.reparentWidget(id, newParentId, index));
        dirty.value = true;
    }

    async function updateProperty(id: string, property: string, value: unknown) {
        // Optimistic local update for snappy inspector feedback.
        const node = findNode(root.value, id);
        if (node) node[property] = value;
        apply(await commands.updateWidgetProperty(id, property, value));
        dirty.value = true;
    }

    async function setBinding(id: string, property: string, path: string | null) {
        apply(await commands.setWidgetBinding(id, property, path));
        dirty.value = true;
    }

    async function setEvent(id: string, event: string, action: string | null) {
        apply(await commands.setWidgetEvent(id, event, action));
        dirty.value = true;
    }

    /** Interactive events a widget type exposes (for the inspector's Events section). */
    function eventsFor(widgetClass: string): string[] {
        switch (shortType(widgetClass)) {
            case 'Button':
                return ['click'];
            case 'Checkbox':
            case 'Toggle':
            case 'Slider':
            case 'Dropdown':
                return ['change'];
            case 'TextInput':
                return ['input'];
            default:
                return [];
        }
    }

    function select(id: string | null) {
        selectedId.value = id;
    }

    function shortType(widgetClass: string): string {
        return widgetClass.split('\\').pop() ?? widgetClass;
    }

    function isContainer(widgetClass: string): boolean {
        return widgetTypes.value.find((t) => t.type === shortType(widgetClass))?.container ?? false;
    }

    function schemaFor(widgetClass: string): WidgetField[] {
        return widgetTypes.value.find((t) => t.type === shortType(widgetClass))?.schema ?? [];
    }

    function parentIdOf(id: string): string | null {
        return findParent(root.value, id)?._id ?? null;
    }

    /**
     * Drop `id` onto `targetId`: nest inside the target if it's a container,
     * otherwise drop it next to the target (under the target's parent).
     * No-op for invalid moves (onto itself or a descendant).
     */
    async function dropOnto(id: string, targetId: string) {
        if (id === targetId || isDescendant(root.value, id, targetId)) return;
        const target = findNode(root.value, targetId);
        if (!target) return;
        const newParentId = isContainer(target._widget) ? targetId : parentIdOf(targetId);
        if (!newParentId) return;
        await reparentWidget(id, newParentId);
    }

    return {
        name,
        root,
        layoutList,
        widgetTypes,
        selectedId,
        loading,
        dirty,
        opened,
        selectedWidget,
        fetchLayoutList,
        fetchWidgetTypes,
        create,
        load,
        save,
        transpileToPhp,
        addWidget,
        removeWidget,
        reparentWidget,
        updateProperty,
        setBinding,
        setEvent,
        eventsFor,
        select,
        isContainer,
        schemaFor,
        parentIdOf,
        dropOnto,
    };
});
