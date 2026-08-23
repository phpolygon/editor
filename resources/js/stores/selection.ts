import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

export const useSelectionStore = defineStore('selection', () => {
    /**
     * The selected entities, in the order they were picked.
     *
     * The LAST one is the active entity — the one the inspector edits and the
     * gizmo anchors on — which matches how a shift-range or ctrl-click reads:
     * whatever you touched last is what you are working on.
     */
    const selectedEntities = ref<string[]>([]);
    const selectedComponent = ref<string | null>(null);

    /**
     * The active entity. Kept as a single value because most of the editor
     * (inspector, workspace jumps, terrain sculpting) acts on exactly one.
     */
    const selectedEntity = computed(() =>
        selectedEntities.value.length > 0
            ? selectedEntities.value[selectedEntities.value.length - 1]
            : null,
    );

    const hasMultiple = computed(() => selectedEntities.value.length > 1);

    function isSelected(name: string): boolean {
        return selectedEntities.value.includes(name);
    }

    /**
     * Select one entity, replacing the selection.
     *
     * `additive` toggles it instead — clicking an already-selected entity with
     * ctrl removes it, which is what every editor does and what makes a
     * mis-click recoverable without starting over.
     */
    function selectEntity(name: string | null, options: { additive?: boolean } = {}) {
        if (name === null) {
            clearSelection();
            return;
        }

        if (!options.additive) {
            selectedEntities.value = [name];
            selectedComponent.value = null;
            return;
        }

        const without = selectedEntities.value.filter((e) => e !== name);
        selectedEntities.value = without.length === selectedEntities.value.length
            ? [...selectedEntities.value, name]
            : without;
        selectedComponent.value = null;
    }

    /** Replace the selection with an explicit set (box-select, range-select). */
    function selectEntities(names: string[]) {
        selectedEntities.value = [...new Set(names)];
        selectedComponent.value = null;
    }

    function selectComponent(className: string | null) {
        selectedComponent.value = className;
    }

    function clearSelection() {
        selectedEntities.value = [];
        selectedComponent.value = null;
    }

    /**
     * Drop entities that no longer exist.
     *
     * A selection outliving its entities (deleted, or a scene reloaded under
     * it) would leave the inspector pointed at nothing and the gizmo attached
     * to a stale object.
     */
    function retain(existing: Set<string>) {
        const kept = selectedEntities.value.filter((name) => existing.has(name));
        if (kept.length !== selectedEntities.value.length) {
            selectedEntities.value = kept;
        }
    }

    return {
        selectedEntities,
        selectedEntity,
        selectedComponent,
        hasMultiple,
        isSelected,
        selectEntity,
        selectEntities,
        selectComponent,
        clearSelection,
        retain,
    };
});
