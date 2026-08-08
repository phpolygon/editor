import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    saveShader: vi.fn(),
    listShaderAssets: vi.fn(),
    loadShaderAsset: vi.fn(),
    getMaterial: vi.fn(),
    saveMaterial: vi.fn(),
    getEntityHierarchy: vi.fn(),
    getMeshes: vi.fn(),
    getMaterials: vi.fn(),
    updateProperty: vi.fn(),
}));

vi.mock('@/three/materialCache', () => ({
    invalidateMaterial: vi.fn(),
    preloadMaterials: vi.fn(),
}));

vi.mock('@/three/meshCache', () => ({ preloadMeshes: vi.fn() }));

import { useShaderEditorStore } from './shaderEditor';
import * as commands from '@/bridge/commands';
import { invalidateMaterial } from '@/three/materialCache';

const mocks = commands as unknown as Record<string, Mock>;
const invalidate = invalidateMaterial as unknown as Mock;

const TARGET = { entity: 'Water', materialId: 'sea' };

describe('useShaderEditorStore — entity round trip', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        for (const fn of Object.values(mocks)) fn.mockReset();
        invalidate.mockReset();
        mocks.listShaderAssets.mockResolvedValue({ shaders: [] });
        mocks.getEntityHierarchy.mockResolvedValue({ entities: [] });
        mocks.getMeshes.mockResolvedValue({ meshes: [] });
        mocks.getMaterials.mockResolvedValue({ materials: [] });
    });

    it('openForEntity reopens the graph of the material’s shader', async () => {
        mocks.listShaderAssets.mockResolvedValue({ shaders: [{ name: 'waves', path: 'shaders/waves.shader.json' }] });
        mocks.getMaterial.mockResolvedValue({ id: 'sea', shader: 'waves' });
        mocks.loadShaderAsset.mockResolvedValue({
            name: 'waves',
            graph: { nodes: [{ id: 'fragment', type: 'fragment' }], connections: [] },
        });

        const store = useShaderEditorStore();
        await store.openForEntity(TARGET);

        expect(mocks.loadShaderAsset).toHaveBeenCalledWith('waves');
        expect(store.name).toBe('waves');
        expect(store.linkedEntity).toEqual({ entity: 'Water', materialId: 'sea' });
    });

    it('openForEntity starts fresh when the material uses the built-in shader', async () => {
        mocks.getMaterial.mockResolvedValue({ id: 'sea', shader: 'default' });

        const store = useShaderEditorStore();
        await store.openForEntity(TARGET);

        expect(mocks.loadShaderAsset).not.toHaveBeenCalled();
        expect(store.name).toBe('sea_shader');
        expect(store.graph.nodes).toHaveLength(1);
        expect(store.linkedEntity?.materialId).toBe('sea');
    });

    it('openForEntity keeps a shader name that has no graph on disk', async () => {
        mocks.getMaterial.mockResolvedValue({ id: 'sea', shader: 'handwritten' });

        const store = useShaderEditorStore();
        await store.openForEntity(TARGET);

        expect(store.name).toBe('handwritten');
    });

    it('applyToEntity saves the shader and repoints the material', async () => {
        mocks.getMaterial.mockResolvedValue({ id: 'sea', shader: 'default', roughness: 0.2 });
        mocks.saveShader.mockResolvedValue({ saved: true, name: 'sea_shader', relativePath: 'shaders/sea_shader.frag.glsl' });

        const store = useShaderEditorStore();
        await store.openForEntity(TARGET);
        const result = await store.applyToEntity();

        expect(result).toEqual({ shader: 'sea_shader', materialId: 'sea' });
        expect(mocks.saveMaterial).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'sea', shader: 'sea_shader', roughness: 0.2 }),
        );
        expect(invalidate).toHaveBeenCalledWith('sea');
        expect(mocks.getEntityHierarchy).toHaveBeenCalled();
    });

    it('applyToEntity without a link is an error', async () => {
        const store = useShaderEditorStore();
        await expect(store.applyToEntity()).rejects.toThrow(/No entity/);
    });

    it('load falls back to an empty graph for a malformed asset', async () => {
        mocks.loadShaderAsset.mockResolvedValue({ name: 'broken', graph: 'not a graph' });

        const store = useShaderEditorStore();
        await store.load('broken');

        expect(store.graph.nodes).toHaveLength(1);
        expect(store.graph.connections).toEqual([]);
    });
});
