import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    listScenes: vi.fn(),
    loadScene: vi.fn(),
    saveScene: vi.fn(),
    getEntityHierarchy: vi.fn(),
    createEntity: vi.fn(),
    createPrimitive: vi.fn(),
    deleteEntity: vi.fn(),
    addComponent: vi.fn(),
    removeComponent: vi.fn(),
    updateProperty: vi.fn(),
    renameEntity: vi.fn(),
    reparentEntity: vi.fn(),
    savePrefab: vi.fn(),
    createPrefabClass: vi.fn(),
    loadPrefabClass: vi.fn(),
    convertPrefabAsset: vi.fn(),
    spawnPrefab: vi.fn(),
    expandScene: vi.fn(),
    getMeshes: vi.fn(),
    getMaterials: vi.fn(),
    undo: vi.fn(),
    redo: vi.fn(),
}));

import { useSceneStore } from './scene';
import * as commands from '@/bridge/commands';

const mocks = commands as unknown as Record<string, Mock>;

describe('useSceneStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        localStorage.clear();
        for (const fn of Object.values(mocks)) fn.mockReset();
    });

    describe('mode', () => {
        it('defaults to 3d', () => {
            const store = useSceneStore();
            expect(store.mode).toBe('3d');
        });

        it('setMode persists to localStorage keyed by scene name', () => {
            const store = useSceneStore();
            store.name = 'MainMenu';
            store.setMode('2d');

            expect(store.mode).toBe('2d');
            expect(localStorage.getItem('phpolygon-editor:scene-mode:MainMenu')).toBe('2d');
        });

        it('setMode does not write to localStorage without a scene name', () => {
            const store = useSceneStore();
            store.setMode('2d');
            expect(localStorage.length).toBe(0);
        });

        it('load restores mode from localStorage for the loaded scene', async () => {
            localStorage.setItem('phpolygon-editor:scene-mode:Level1', '2d');
            mocks.loadScene.mockResolvedValueOnce({ name: 'Level1', entities: [], dirty: false });

            const store = useSceneStore();
            await store.load('Level1');
            expect(store.mode).toBe('2d');
        });

        it('load defaults to 3d when no stored mode exists', async () => {
            mocks.loadScene.mockResolvedValueOnce({ name: 'Fresh', entities: [], dirty: false });

            const store = useSceneStore();
            await store.load('Fresh');
            expect(store.mode).toBe('3d');
        });
    });

    describe('createPrimitive', () => {
        it('refreshes hierarchy and marks dirty after spawn', async () => {
            mocks.createPrimitive.mockResolvedValueOnce({
                created: 'Box', parent: null, meshId: 'editor_primitive_box', materialId: 'editor_default_material',
            });
            mocks.getEntityHierarchy.mockResolvedValueOnce({
                entities: [{ name: 'Box', components: [], children: [] }],
            });

            const store = useSceneStore();
            const name = await store.createPrimitive('box');

            expect(name).toBe('Box');
            expect(store.entities).toHaveLength(1);
            expect(store.dirty).toBe(true);
        });
    });

    describe('updateProperty', () => {
        it('optimistically writes to local entity state', async () => {
            mocks.updateProperty.mockResolvedValueOnce({ updated: true });

            const store = useSceneStore();
            store.entities = [
                {
                    name: 'Box',
                    children: [],
                    components: [
                        { _class: 'PHPolygon\\Component\\Transform3D', properties: { position: { x: 0, y: 0, z: 0 } } },
                    ],
                },
            ];

            await store.updateProperty('Box', 'PHPolygon\\Component\\Transform3D', 'position', { x: 5, y: 0, z: 0 });

            const transform = store.entities[0].components[0];
            expect((transform.properties.position as { x: number }).x).toBe(5);
            expect(store.dirty).toBe(true);
        });
    });

    describe('spawnPrefab', () => {
        it('refreshes hierarchy and returns the spawned root name', async () => {
            mocks.spawnPrefab.mockResolvedValueOnce({ spawned: 'Car_2', parent: null });
            mocks.getEntityHierarchy.mockResolvedValueOnce({
                entities: [{ name: 'Car_2', components: [], children: [] }],
            });

            const store = useSceneStore();
            const name = await store.spawnPrefab('prefabs/Car.prefab.json');
            expect(name).toBe('Car_2');
            expect(store.dirty).toBe(true);
        });
    });

    describe('entityCount', () => {
        it('counts nested children', () => {
            const store = useSceneStore();
            store.entities = [
                {
                    name: 'Root',
                    components: [],
                    children: [
                        { name: 'A', components: [], children: [] },
                        { name: 'B', components: [], children: [{ name: 'B1', components: [], children: [] }] },
                    ],
                },
            ];
            expect(store.entityCount).toBe(4);
        });
    });

    describe('findEntity', () => {
        it('walks into descendants', () => {
            const store = useSceneStore();
            store.entities = [
                {
                    name: 'Root',
                    components: [],
                    children: [{ name: 'Deep', components: [], children: [] }],
                },
            ];
            const found = store.findEntity('Deep');
            expect(found?.name).toBe('Deep');
        });

        it('returns null for unknown entity', () => {
            const store = useSceneStore();
            expect(store.findEntity('Missing')).toBeNull();
        });
    });

    describe('prefab editing', () => {
        const PREFAB = 'Game\\Prefab\\Lamp';

        function openSession() {
            mocks.loadPrefabClass.mockResolvedValue({
                editingPrefab: PREFAB,
                name: 'Lamp',
                writable: true,
                entities: [{ name: 'Lamp', components: [], children: [] }],
            });
        }

        it('replaces the document with the prefab contents', async () => {
            openSession();
            const store = useSceneStore();

            await store.openPrefab(PREFAB);

            expect(store.editingPrefab).toBe(PREFAB);
            expect(store.name).toBe('Lamp');
            expect(store.entities[0].name).toBe('Lamp');
        });

        it('saves as a prefab class, not as a scene', async () => {
            // The document holds the prefab's contents; writing a scene file
            // here would create one nobody asked for.
            openSession();
            const store = useSceneStore();
            await store.openPrefab(PREFAB);

            await store.save();

            expect(mocks.saveScene).not.toHaveBeenCalled();
            expect(mocks.createPrefabClass).toHaveBeenCalledWith('Lamp', {
                className: 'Lamp',
                overwrite: true,
            });
        });

        it('reports a hand-edited prefab as not writable', async () => {
            mocks.loadPrefabClass.mockResolvedValue({
                editingPrefab: PREFAB,
                name: 'Lamp',
                writable: false,
                entities: [{ name: 'Lamp', components: [], children: [] }],
            });
            const store = useSceneStore();

            await store.openPrefab(PREFAB);

            expect(store.prefabWritable).toBe(false);
        });

        it('returns to the scene it was opened from', async () => {
            mocks.loadScene.mockResolvedValue({ name: 'Level', entities: [] });
            mocks.getMeshes.mockResolvedValue({ meshes: [] });
            mocks.getMaterials.mockResolvedValue({ materials: [] });
            mocks.expandScene.mockResolvedValue({ expanded: false, entities: [] });
            openSession();

            const store = useSceneStore();
            await store.load('Level');
            await store.openPrefab(PREFAB);

            const returned = await store.closePrefab();

            expect(returned).toBe(true);
            expect(store.editingPrefab).toBeNull();
            expect(store.name).toBe('Level');
        });

        it('says so when there is no scene to return to', async () => {
            openSession();
            const store = useSceneStore();
            await store.openPrefab(PREFAB);

            expect(await store.closePrefab()).toBe(false);
            expect(store.editingPrefab).toBeNull();
        });

        it('loading a scene leaves prefab editing', async () => {
            mocks.loadScene.mockResolvedValue({ name: 'Level', entities: [] });
            mocks.getMeshes.mockResolvedValue({ meshes: [] });
            mocks.getMaterials.mockResolvedValue({ materials: [] });
            mocks.expandScene.mockResolvedValue({ expanded: false, entities: [] });
            openSession();
            const store = useSceneStore();
            await store.openPrefab(PREFAB);

            await store.load('Level');

            expect(store.editingPrefab).toBeNull();
        });
    });

    describe('flattened export', () => {
        it('asks the backend to expand prefab references', async () => {
            mocks.saveScene.mockResolvedValue({ saved: 'x.php', flattened: 2, notFlattened: [] });
            const store = useSceneStore();

            const result = await store.exportFlattened();

            expect(mocks.saveScene).toHaveBeenCalledWith({ flatten: true });
            expect(result.flattened).toBe(2);
        });

        it('refuses while a prefab is open', async () => {
            // The document is the prefab's contents, not a scene to flatten.
            mocks.loadPrefabClass.mockResolvedValue({
                editingPrefab: 'Game\\Prefab\\Lamp',
                name: 'Lamp',
                writable: true,
                entities: [{ name: 'Lamp', components: [], children: [] }],
            });
            const store = useSceneStore();
            await store.openPrefab('Game\\Prefab\\Lamp');

            await expect(store.exportFlattened()).rejects.toThrow(/Close the prefab/);
        });
    });
});
