import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    getPrefabBaseline: vi.fn(),
    revertPrefabOverride: vi.fn(),
}));

import { usePrefabStore } from './prefab';
import * as commands from '@/bridge/commands';
import type { EntityNode } from '@/types';

const mocks = commands as unknown as Record<string, Mock>;

const PREFAB = 'Game\\Prefab\\Lantern';
const MESH = 'PHPolygon\\Component\\MeshRenderer';
const LIGHT = 'PHPolygon\\Component\\PointLight';

const BASELINE = [
    { _class: MESH, properties: { meshId: 'lantern_post', materialId: 'metal' } },
    { _class: LIGHT, properties: { intensity: 2.0, radius: 8.0 } },
];

function instance(components: EntityNode['components']): EntityNode {
    return { name: 'Lantern_A', prefab: PREFAB, components, children: [] };
}

describe('usePrefabStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        for (const fn of Object.values(mocks)) fn.mockReset();
        mocks.getPrefabBaseline.mockResolvedValue({
            class: PREFAB,
            available: true,
            components: BASELINE,
        });
    });

    it('shows the prefab components an instance does not override', async () => {
        // Without merging, a fully-featured prefab object would show up in the
        // inspector as an almost empty entity.
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const shown = store.componentsFor(instance([]));

        expect(shown.map((c) => c.data._class)).toEqual([MESH, LIGHT]);
        expect(shown.every((c) => c.inherited)).toBe(true);
        expect(shown.every((c) => c.overridden.size === 0)).toBe(true);
    });

    it('marks only the properties that differ from the prefab', async () => {
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const shown = store.componentsFor(
            instance([{ _class: LIGHT, properties: { intensity: 5.0 } }]),
        );

        const light = shown.find((c) => c.data._class === LIGHT)!;
        expect(light.inherited).toBe(false);
        expect([...light.overridden]).toEqual(['intensity']);
        expect(light.data.properties.intensity).toBe(5.0);
        // The value it does not override still shows, from the prefab.
        expect(light.data.properties.radius).toBe(8.0);
    });

    it('does not mark a stored value that equals the prefab', async () => {
        // The backend prunes these, but a scene authored elsewhere can carry
        // one, and calling it an override would be a lie.
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const shown = store.componentsFor(
            instance([{ _class: LIGHT, properties: { intensity: 2.0 } }]),
        );

        expect(shown.find((c) => c.data._class === LIGHT)!.overridden.size).toBe(0);
    });

    it('treats a whole number and its float as the same value', async () => {
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const shown = store.componentsFor(
            instance([{ _class: LIGHT, properties: { intensity: 2 } }]),
        );

        expect(shown.find((c) => c.data._class === LIGHT)!.overridden.size).toBe(0);
    });

    it('compares nested values structurally', async () => {
        mocks.getPrefabBaseline.mockResolvedValue({
            class: PREFAB,
            available: true,
            components: [{ _class: LIGHT, properties: { color: { r: 1, g: 1, b: 1, a: 1 } } }],
        });
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const same = store.componentsFor(
            instance([{ _class: LIGHT, properties: { color: { r: 1, g: 1, b: 1, a: 1 } } }]),
        );
        const differs = store.componentsFor(
            instance([{ _class: LIGHT, properties: { color: { r: 1, g: 0, b: 0, a: 1 } } }]),
        );

        expect(same[0].overridden.size).toBe(0);
        expect([...differs[0].overridden]).toEqual(['color']);
    });

    it('counts every value on a component the prefab does not have as an override', async () => {
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const extra = 'PHPolygon\\Component\\Transform3D';
        const shown = store.componentsFor(
            instance([{ _class: extra, properties: { position: { x: 1, y: 0, z: 0 } } }]),
        );

        const added = shown.find((c) => c.data._class === extra)!;
        expect(added.inherited).toBe(false);
        expect([...added.overridden]).toEqual(['position']);
    });

    it('falls back to the entity alone when the prefab cannot be built', async () => {
        mocks.getPrefabBaseline.mockResolvedValue({ class: PREFAB, available: false, components: [] });
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);

        const shown = store.componentsFor(
            instance([{ _class: LIGHT, properties: { intensity: 5.0 } }]),
        );

        expect(shown).toHaveLength(1);
        expect(shown[0].overridden.size).toBe(0);
    });

    it('leaves a plain entity untouched', () => {
        const store = usePrefabStore();

        const shown = store.componentsFor({
            name: 'Plain',
            components: [{ _class: LIGHT, properties: { intensity: 1.0 } }],
            children: [],
        });

        expect(shown).toHaveLength(1);
        expect(shown[0].inherited).toBe(false);
        expect(shown[0].overridden.size).toBe(0);
    });

    it('fetches a baseline once per class', async () => {
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);
        await store.loadBaseline(PREFAB);

        expect(mocks.getPrefabBaseline).toHaveBeenCalledTimes(1);
    });

    it('refetches after the prefab source changed', async () => {
        const store = usePrefabStore();
        await store.loadBaseline(PREFAB);
        store.forget(PREFAB);
        await store.loadBaseline(PREFAB);

        expect(mocks.getPrefabBaseline).toHaveBeenCalledTimes(2);
    });

    it('survives a failing baseline request', async () => {
        mocks.getPrefabBaseline.mockRejectedValue(new Error('boom'));
        const store = usePrefabStore();

        const baseline = await store.loadBaseline(PREFAB);

        expect(baseline).toBeNull();
        expect(store.componentsFor(instance([]))).toEqual([]);
    });
});
