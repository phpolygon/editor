import { describe, expect, it, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

import { useSelectionStore } from './selection';

describe('useSelectionStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('starts empty', () => {
        const store = useSelectionStore();
        expect(store.selectedEntity).toBeNull();
        expect(store.selectedEntities).toEqual([]);
        expect(store.hasMultiple).toBe(false);
    });

    it('replaces the selection on a plain click', () => {
        const store = useSelectionStore();
        store.selectEntity('A');
        store.selectEntity('B');

        expect(store.selectedEntities).toEqual(['B']);
        expect(store.selectedEntity).toBe('B');
    });

    it('adds with ctrl and keeps the newest as active', () => {
        // The active entity is what the inspector edits, and "what you touched
        // last" is what reads as current.
        const store = useSelectionStore();
        store.selectEntity('A');
        store.selectEntity('B', { additive: true });

        expect(store.selectedEntities).toEqual(['A', 'B']);
        expect(store.selectedEntity).toBe('B');
        expect(store.hasMultiple).toBe(true);
    });

    it('ctrl-clicking a selected entity removes it', () => {
        // A mis-click has to be recoverable without starting the selection over.
        const store = useSelectionStore();
        store.selectEntity('A');
        store.selectEntity('B', { additive: true });

        store.selectEntity('A', { additive: true });

        expect(store.selectedEntities).toEqual(['B']);
    });

    it('reports membership for the whole selection', () => {
        const store = useSelectionStore();
        store.selectEntity('A');
        store.selectEntity('B', { additive: true });

        expect(store.isSelected('A')).toBe(true);
        expect(store.isSelected('B')).toBe(true);
        expect(store.isSelected('C')).toBe(false);
    });

    it('replaces the selection with an explicit set', () => {
        const store = useSelectionStore();
        store.selectEntity('A');

        store.selectEntities(['X', 'Y', 'X']);

        expect(store.selectedEntities).toEqual(['X', 'Y']);
    });

    it('clears on a null selection', () => {
        const store = useSelectionStore();
        store.selectEntity('A');
        store.selectComponent('Some\\Component');

        store.selectEntity(null);

        expect(store.selectedEntities).toEqual([]);
        expect(store.selectedComponent).toBeNull();
    });

    it('drops entities that no longer exist', () => {
        // A selection outliving its entities points the inspector at nothing
        // and leaves the gizmo on a stale object.
        const store = useSelectionStore();
        store.selectEntities(['A', 'B', 'C']);

        store.retain(new Set(['A', 'C']));

        expect(store.selectedEntities).toEqual(['A', 'C']);
    });

    it('leaves an intact selection alone', () => {
        const store = useSelectionStore();
        store.selectEntities(['A', 'B']);
        const before = store.selectedEntities;

        store.retain(new Set(['A', 'B']));

        expect(store.selectedEntities).toBe(before);
    });

    it('forgets the selected component when the entity changes', () => {
        const store = useSelectionStore();
        store.selectEntity('A');
        store.selectComponent('Some\\Component');

        store.selectEntity('B');

        expect(store.selectedComponent).toBeNull();
    });
});
