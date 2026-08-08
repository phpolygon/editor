import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    evaluateProceduralMesh: vi.fn(),
    saveMesh: vi.fn(),
    saveRawMesh: vi.fn(),
    saveMaterial: vi.fn(),
    saveTexture: vi.fn(),
    listMeshAssets: vi.fn(),
    loadMeshAsset: vi.fn(),
    deleteMeshAsset: vi.fn(),
    renameMeshAsset: vi.fn(),
    listMeshes: vi.fn(),
    getMesh: vi.fn(),
    updateProperty: vi.fn(),
    getEntityHierarchy: vi.fn(),
    getMeshes: vi.fn(),
    getMaterials: vi.fn(),
}));

vi.mock('@/three/meshCache', () => ({
    setMesh: vi.fn(),
    preloadMeshes: vi.fn(),
}));

vi.mock('@/three/materialCache', () => ({
    preloadMaterials: vi.fn(),
}));

import { useMeshEditorStore } from './meshEditor';
import * as commands from '@/bridge/commands';
import { setMesh } from '@/three/meshCache';
import type { EntityMeshTarget } from '@/scene/entityAssets';

const mocks = commands as unknown as Record<string, Mock>;
const setMeshMock = setMesh as unknown as Mock;

const MESH = { id: '', version: 1, vertices: [0, 0, 0], normals: [0, 1, 0], uvs: [0, 0], indices: [0], vertexCount: 1, triangleCount: 0 };

function graphTarget(overrides: Partial<EntityMeshTarget> = {}): EntityMeshTarget {
    return {
        entity: 'Player',
        componentClass: 'PHPolygon\\Component\\ProceduralMesh',
        kind: 'graph',
        nodes: [{ id: 'sphere', type: 'sphere' }],
        output: 'sphere',
        meshId: '',
        ...overrides,
    };
}

function assetTarget(overrides: Partial<EntityMeshTarget> = {}): EntityMeshTarget {
    return {
        entity: 'Crate',
        componentClass: 'PHPolygon\\Component\\MeshRenderer',
        kind: 'asset',
        nodes: [],
        output: '',
        meshId: 'crate',
        ...overrides,
    };
}

describe('useMeshEditorStore — entity round trip', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        for (const fn of Object.values(mocks)) fn.mockReset();
        setMeshMock.mockReset();
        mocks.evaluateProceduralMesh.mockResolvedValue(MESH);
        mocks.listMeshAssets.mockResolvedValue({ meshes: [] });
        mocks.getEntityHierarchy.mockResolvedValue({ entities: [] });
        mocks.getMeshes.mockResolvedValue({ meshes: [] });
        mocks.getMaterials.mockResolvedValue({ materials: [] });
    });

    it('openForEntity loads a ProceduralMesh graph and links the entity', async () => {
        const store = useMeshEditorStore();
        await store.openForEntity(graphTarget());

        expect(store.graph).toEqual({ nodes: [{ id: 'sphere', type: 'sphere' }], output: 'sphere' });
        expect(store.editMode).toBe(false);
        expect(store.linkedEntity).toEqual({
            entity: 'Player',
            componentClass: 'PHPolygon\\Component\\ProceduralMesh',
            kind: 'graph',
        });
        expect(mocks.evaluateProceduralMesh).toHaveBeenCalled();
    });

    it('openForEntity copies the graph so editing does not mutate scene state', async () => {
        const target = graphTarget();
        const store = useMeshEditorStore();
        await store.openForEntity(target);

        store.addNodeOfType('box');

        expect(target.nodes).toHaveLength(1);
    });

    it('openForEntity falls back to a starter graph for an empty ProceduralMesh', async () => {
        const store = useMeshEditorStore();
        await store.openForEntity(graphTarget({ nodes: [], output: '' }));

        expect(store.graph.nodes).toHaveLength(1);
        expect(store.graph.output).toBe('box');
    });

    it('openForEntity loads a saved asset when the meshId is one of ours', async () => {
        mocks.listMeshAssets.mockResolvedValue({ meshes: [{ name: 'crate', path: 'crate.mesh.json' }] });
        mocks.loadMeshAsset.mockResolvedValue({ name: 'crate', nodes: [{ id: 'box', type: 'box' }], output: 'box', raw: null });

        const store = useMeshEditorStore();
        await store.openForEntity(assetTarget());

        expect(mocks.loadMeshAsset).toHaveBeenCalledWith('crate');
        expect(mocks.getMesh).not.toHaveBeenCalled();
        expect(store.linkedEntity?.entity).toBe('Crate');
    });

    it('openForEntity pulls an unknown meshId in as baked project geometry', async () => {
        mocks.getMesh.mockResolvedValue({ ...MESH, id: 'crate' });

        const store = useMeshEditorStore();
        await store.openForEntity(assetTarget());

        expect(mocks.getMesh).toHaveBeenCalledWith('crate');
        expect(store.editMode).toBe(true);
        expect(store.linkedEntity?.kind).toBe('asset');
    });

    it('applyToEntity writes the graph back to a ProceduralMesh', async () => {
        const store = useMeshEditorStore();
        await store.openForEntity(graphTarget());

        const result = await store.applyToEntity();

        expect(result).toEqual({ kind: 'graph' });
        expect(mocks.updateProperty).toHaveBeenCalledWith(
            'Player',
            'PHPolygon\\Component\\ProceduralMesh',
            'nodes',
            [{ id: 'sphere', type: 'sphere' }],
        );
        expect(mocks.updateProperty).toHaveBeenCalledWith(
            'Player',
            'PHPolygon\\Component\\ProceduralMesh',
            'output',
            'sphere',
        );
        expect(mocks.saveRawMesh).not.toHaveBeenCalled();
    });

    it('applyToEntity refuses to write vertex edits back to a ProceduralMesh', async () => {
        const store = useMeshEditorStore();
        await store.openForEntity(graphTarget());
        store.enterEditMode();

        await expect(store.applyToEntity()).rejects.toThrow(/ProceduralMesh/);
        expect(mocks.updateProperty).not.toHaveBeenCalled();
    });

    it('applyToEntity bakes the mesh and repoints a MeshRenderer at it', async () => {
        mocks.getMesh.mockResolvedValue({ ...MESH, id: 'crate' });
        mocks.saveRawMesh.mockResolvedValue({ saved: true, name: 'crate', path: 'p', relativePath: 'meshes/crate.mesh.json' });

        const store = useMeshEditorStore();
        await store.openForEntity(assetTarget());

        const result = await store.applyToEntity();

        expect(result).toEqual({ kind: 'asset', meshId: 'crate' });
        expect(mocks.saveRawMesh).toHaveBeenCalledWith('crate', {
            vertices: MESH.vertices,
            normals: MESH.normals,
            uvs: MESH.uvs,
            indices: MESH.indices,
        });
        // The viewport caches by id, so the new geometry has to be seeded.
        expect(setMeshMock).toHaveBeenCalledWith('crate', expect.objectContaining({ id: 'crate', vertices: MESH.vertices }));
        expect(mocks.updateProperty).toHaveBeenCalledWith(
            'Crate',
            'PHPolygon\\Component\\MeshRenderer',
            'meshId',
            'crate',
        );
        expect(mocks.getEntityHierarchy).toHaveBeenCalled();
    });

    it('applyToEntity keeps a saved node graph instead of baking over it', async () => {
        mocks.listMeshAssets.mockResolvedValue({ meshes: [{ name: 'crate', path: 'crate.mesh.json' }] });
        mocks.loadMeshAsset.mockResolvedValue({ name: 'crate', nodes: [{ id: 'box', type: 'box' }], output: 'box', raw: null });
        mocks.saveRawMesh.mockResolvedValue({ saved: true, name: 'crate_baked', path: 'p', relativePath: 'r' });

        const store = useMeshEditorStore();
        await store.openForEntity(assetTarget());

        const result = await store.applyToEntity();

        expect(mocks.saveRawMesh).toHaveBeenCalledWith('crate_baked', expect.anything());
        expect(result.meshId).toBe('crate_baked');
        expect(mocks.updateProperty).toHaveBeenCalledWith(
            'Crate',
            'PHPolygon\\Component\\MeshRenderer',
            'meshId',
            'crate_baked',
        );
    });

    it('applyToEntity overwrites the same baked asset on a second apply', async () => {
        mocks.getMesh.mockResolvedValue({ ...MESH, id: 'crate' });
        mocks.saveRawMesh.mockResolvedValue({ saved: true, name: 'crate', path: 'p', relativePath: 'r' });

        const store = useMeshEditorStore();
        await store.openForEntity(assetTarget());
        await store.applyToEntity();
        // The bake is a saved asset now; applying again must not derive a name.
        mocks.listMeshAssets.mockResolvedValue({ meshes: [{ name: 'crate', path: 'crate.mesh.json' }] });
        await store.applyToEntity();

        expect(mocks.saveRawMesh).toHaveBeenNthCalledWith(2, 'crate', expect.anything());
    });

    it('applyToEntity without a link is an error', async () => {
        const store = useMeshEditorStore();
        await expect(store.applyToEntity()).rejects.toThrow(/No entity/);
    });

    it('loading another asset drops the entity link', async () => {
        mocks.loadMeshAsset.mockResolvedValue({ name: 'other', nodes: [{ id: 'box', type: 'box' }], output: 'box', raw: null });

        const store = useMeshEditorStore();
        await store.openForEntity(graphTarget());
        await store.load('other');

        expect(store.linkedEntity).toBeNull();
    });
});
