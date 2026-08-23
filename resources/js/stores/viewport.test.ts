import { describe, expect, it, beforeEach, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';

import { useViewportStore } from './viewport';

const STORAGE_KEY = 'phpolygon-editor:viewport';

describe('useViewportStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        localStorage.clear();
    });

    it('starts with snapping off and world-space gizmos', () => {
        const store = useViewportStore();

        expect(store.snapEnabled).toBe(false);
        expect(store.gizmoSpace).toBe('world');
        expect(store.showGrid).toBe(true);
        expect(store.translateStep).toBe(1);
    });

    it('persists settings across sessions', async () => {
        const store = useViewportStore();
        store.toggleSnap();
        store.translateStep = 0.25;
        store.toggleGizmoSpace();
        await nextTick();

        // A fresh pinia is what a new session looks like.
        setActivePinia(createPinia());
        const reloaded = useViewportStore();

        expect(reloaded.snapEnabled).toBe(true);
        expect(reloaded.translateStep).toBe(0.25);
        expect(reloaded.gizmoSpace).toBe('local');
    });

    it('falls back to defaults for steps the UI does not offer', () => {
        // A hand-edited or stale value would otherwise leave the snap bar
        // showing a step it has no option for.
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ translateStep: 3.7, rotateStep: 1, scaleStep: 99, gizmoSpace: 'galactic' }),
        );

        const store = useViewportStore();

        expect(store.translateStep).toBe(1);
        expect(store.rotateStep).toBe(15);
        expect(store.scaleStep).toBe(0.25);
        expect(store.gizmoSpace).toBe('world');
    });

    it('survives unreadable storage', () => {
        localStorage.setItem(STORAGE_KEY, '{not json');

        const store = useViewportStore();

        expect(store.snapEnabled).toBe(false);
        expect(store.translateStep).toBe(1);
    });

    it('keeps working when storage refuses writes', async () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('QuotaExceededError');
        });

        const store = useViewportStore();
        store.toggleSnap();
        await nextTick();

        expect(store.snapEnabled).toBe(true);
        setItem.mockRestore();
    });
});
