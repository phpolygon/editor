import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    bakeTerrainMesh: vi.fn(),
    createTerrainEntity: vi.fn(),
    deleteTerrainAsset: vi.fn(),
    listTerrainAssets: vi.fn(),
    loadTerrain: vi.fn(),
    saveMaterial: vi.fn(),
    saveTerrain: vi.fn(),
    saveTexture: vi.fn(),
    updateProperty: vi.fn(),
    getEntityHierarchy: vi.fn(),
    getMeshes: vi.fn(),
    getMaterials: vi.fn(),
}));

vi.mock('@/three/meshCache', () => ({ preloadMeshes: vi.fn() }));
vi.mock('@/three/materialCache', () => ({ preloadMaterials: vi.fn(), invalidateMaterial: vi.fn() }));

import { useTerrainEditorStore } from './terrainEditor';
import * as commands from '@/bridge/commands';
import { createHeightmap, encodeHeights } from '@/terrain/heightmap';
import type { EntityTerrainTarget } from '@/scene/entityAssets';

const mocks = commands as unknown as Record<string, Mock>;

const TERRAIN_CLASS = 'PHPolygon\\Component\\Terrain';
const COLLIDER_CLASS = 'PHPolygon\\Component\\HeightmapCollider3D';
const SCATTER_CLASS = 'PHPolygon\\Component\\TerrainScatter';

/** A small terrain so the encoded heightmap stays cheap in tests. */
function encodedHeights(gridWidth: number, gridDepth: number): string {
    return encodeHeights(createHeightmap({ gridWidth, gridDepth }).samples);
}

function target(overrides: Partial<EntityTerrainTarget> = {}): EntityTerrainTarget {
    return {
        entity: 'Island',
        componentClass: TERRAIN_CLASS,
        colliderComponentClass: null,
        scatterComponentClass: null,
        assetName: '',
        scatterSets: [],
        component: {
            _class: TERRAIN_CLASS,
            properties: {
                gridWidth: 9,
                gridDepth: 9,
                sizeX: 64,
                sizeZ: 64,
                minHeight: 0,
                maxHeight: 20,
                heights: encodedHeights(9, 9),
                chunkSize: 8,
                materialId: 'grass',
            },
        },
        ...overrides,
    };
}

describe('useTerrainEditorStore — entity round trip', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        for (const fn of Object.values(mocks)) fn.mockReset();
        mocks.listTerrainAssets.mockResolvedValue({ terrains: [] });
        mocks.getEntityHierarchy.mockResolvedValue({ entities: [] });
        mocks.getMeshes.mockResolvedValue({ meshes: [] });
        mocks.getMaterials.mockResolvedValue({ materials: [] });
        mocks.saveTerrain.mockResolvedValue({ saved: true, name: 'Island', path: 'p', relativePath: 'r' });
    });

    it('openForEntity takes the shape from the component and links the entity', async () => {
        const store = useTerrainEditorStore();
        await store.openForEntity(target());

        expect(store.gridWidth).toBe(9);
        expect(store.heightmap.sizeX).toBe(64);
        expect(store.materialId).toBe('grass');
        expect(store.name).toBe('Island');
        expect(mocks.loadTerrain).not.toHaveBeenCalled();
        expect(store.linkedEntity).toMatchObject({ entity: 'Island', componentClass: TERRAIN_CLASS });
    });

    it('openForEntity tops the component up with the asset’s paint layers', async () => {
        mocks.loadTerrain.mockResolvedValue({
            name: 'island',
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 64,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 20,
            heights: encodedHeights(9, 9),
            chunkSize: 8,
            materialId: 'grass',
            layers: [{ id: 'rock', name: 'Rock', materialId: '', uvScale: 8, minHeight: 0, maxHeight: 1, minSlope: 0, maxSlope: 90 }],
            splat: '',
            scatter: [],
        });

        const store = useTerrainEditorStore();
        await store.openForEntity(target({ assetName: 'island' }));

        expect(mocks.loadTerrain).toHaveBeenCalledWith('island');
        expect(store.layers).toHaveLength(1);
    });

    it('openForEntity ignores asset paint data painted at another resolution', async () => {
        mocks.loadTerrain.mockResolvedValue({
            name: 'island',
            gridWidth: 129,
            gridDepth: 129,
            sizeX: 64,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 20,
            heights: encodedHeights(5, 5),
            chunkSize: 8,
            materialId: 'grass',
            layers: [{ id: 'rock', name: 'Rock', materialId: '', uvScale: 8, minHeight: 0, maxHeight: 1, minSlope: 0, maxSlope: 90 }],
            splat: '',
            scatter: [],
        });

        const store = useTerrainEditorStore();
        await store.openForEntity(target({ assetName: 'island' }));

        expect(store.layers).toEqual([]);
        expect(store.gridWidth).toBe(9);
    });

    it('applyToEntity saves the asset and writes every terrain property back', async () => {
        const store = useTerrainEditorStore();
        await store.openForEntity(target());

        const result = await store.applyToEntity();

        expect(result).toEqual({ entity: 'Island', asset: 'Island' });
        expect(mocks.saveTerrain).toHaveBeenCalled();

        const written = Object.fromEntries(
            mocks.updateProperty.mock.calls
                .filter((c) => c[1] === TERRAIN_CLASS)
                .map((c) => [c[2], c[3]]),
        );
        expect(written).toMatchObject({
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 64,
            sizeZ: 64,
            chunkSize: 8,
            materialId: 'grass',
            meshIdPrefix: 'Island',
        });
        expect(typeof written.heights).toBe('string');
        expect(mocks.getEntityHierarchy).toHaveBeenCalled();
    });

    it('applyToEntity keeps the collider bounds in step', async () => {
        const store = useTerrainEditorStore();
        await store.openForEntity(target({ colliderComponentClass: COLLIDER_CLASS }));
        await store.applyToEntity();

        const collider = Object.fromEntries(
            mocks.updateProperty.mock.calls
                .filter((c) => c[1] === COLLIDER_CLASS)
                .map((c) => [c[2], c[3]]),
        );
        expect(collider).toEqual({
            gridWidth: 9,
            gridDepth: 9,
            worldMinX: -32,
            worldMaxX: 32,
            worldMinZ: -32,
            worldMaxZ: 32,
        });
    });

    it('applyToEntity only writes scatter when the entity carries the component', async () => {
        const store = useTerrainEditorStore();
        await store.openForEntity(target());
        await store.applyToEntity();

        expect(mocks.updateProperty.mock.calls.some((c) => c[1] === SCATTER_CLASS)).toBe(false);
    });

    it('applyToEntity without a link is an error', async () => {
        const store = useTerrainEditorStore();
        await expect(store.applyToEntity()).rejects.toThrow(/No entity/);
    });

    it('loading a terrain asset drops the entity link', async () => {
        mocks.loadTerrain.mockResolvedValue({
            name: 'other',
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 64,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 20,
            heights: encodedHeights(9, 9),
            chunkSize: 8,
            materialId: '',
            layers: [],
            splat: '',
            scatter: [],
        });

        const store = useTerrainEditorStore();
        await store.openForEntity(target());
        await store.load('other');

        expect(store.linkedEntity).toBeNull();
    });
});
