import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('@/bridge/commands', () => ({
    getMaterial: vi.fn(),
    saveMaterial: vi.fn(),
    listMaterialAssets: vi.fn(),
    updateProperty: vi.fn(),
    getEntityHierarchy: vi.fn(),
    getMeshes: vi.fn(),
    getMaterials: vi.fn(),
}));

vi.mock('@/three/materialCache', () => ({
    invalidateMaterial: vi.fn(),
    preloadMaterials: vi.fn(),
}));

vi.mock('@/three/meshCache', () => ({ preloadMeshes: vi.fn() }));

import { useMaterialEditorStore } from './materialEditor';
import * as commands from '@/bridge/commands';
import type { EntityMaterialTarget } from '@/scene/entityAssets';

const mocks = commands as unknown as Record<string, Mock>;

const TARGET: EntityMaterialTarget = {
    entity: 'Crate',
    componentClass: 'PHPolygon\\Component\\MeshRenderer',
    materialId: 'wood',
};

describe('useMaterialEditorStore — entity round trip', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        for (const fn of Object.values(mocks)) fn.mockReset();
        mocks.listMaterialAssets.mockResolvedValue({ materials: [] });
        mocks.getEntityHierarchy.mockResolvedValue({ entities: [] });
        mocks.getMeshes.mockResolvedValue({ meshes: [] });
        mocks.getMaterials.mockResolvedValue({ materials: [] });
    });

    it('openForEntity loads the entity’s material and links it', async () => {
        mocks.getMaterial.mockResolvedValue({ id: 'wood', roughness: 0.3 });

        const store = useMaterialEditorStore();
        await store.openForEntity(TARGET);

        expect(mocks.getMaterial).toHaveBeenCalledWith('wood');
        expect(store.material.id).toBe('wood');
        expect(store.linkedEntity).toEqual({
            entity: 'Crate',
            componentClass: 'PHPolygon\\Component\\MeshRenderer',
        });
    });

    it('openForEntity starts a fresh material when the entity has none', async () => {
        const store = useMaterialEditorStore();
        await store.openForEntity({ ...TARGET, materialId: '' });

        expect(mocks.getMaterial).toHaveBeenCalledWith('Crate_mat');
        expect(store.material.id).toBe('Crate_mat');
        expect(store.linkedEntity?.entity).toBe('Crate');
    });

    it('openForEntity authors under the id when no asset backs it', async () => {
        mocks.getMaterial.mockRejectedValue(new Error('Unknown material: wood'));

        const store = useMaterialEditorStore();
        await store.openForEntity(TARGET);

        expect(store.material.id).toBe('wood');
        expect(store.linkedEntity?.entity).toBe('Crate');
    });

    it('applyToEntity saves and points the component at the material', async () => {
        mocks.getMaterial.mockResolvedValue({ id: 'wood' });
        mocks.saveMaterial.mockResolvedValue({ saved: true, id: 'wood', path: 'p', relativePath: 'r' });

        const store = useMaterialEditorStore();
        await store.openForEntity(TARGET);
        const result = await store.applyToEntity();

        expect(result).toEqual({ materialId: 'wood' });
        expect(mocks.saveMaterial).toHaveBeenCalled();
        expect(mocks.updateProperty).toHaveBeenCalledWith(
            'Crate',
            'PHPolygon\\Component\\MeshRenderer',
            'materialId',
            'wood',
        );
        expect(mocks.getEntityHierarchy).toHaveBeenCalled();
    });

    it('applyToEntity without a link is an error', async () => {
        const store = useMaterialEditorStore();
        await expect(store.applyToEntity()).rejects.toThrow(/No entity/);
    });

    it('loading another material drops the entity link', async () => {
        mocks.getMaterial.mockResolvedValue({ id: 'wood' });

        const store = useMaterialEditorStore();
        await store.openForEntity(TARGET);
        await store.load('metal');

        expect(store.linkedEntity).toBeNull();
    });
});
