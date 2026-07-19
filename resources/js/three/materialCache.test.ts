import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import * as THREE from 'three';

vi.mock('@/bridge/commands', () => ({
    getMesh: vi.fn(),
    getMaterial: vi.fn(),
    assetFileUrl: (p: string) => `/test-assets/${p}`,
}));

import { loadMaterial, clearMaterialCache, placeholderMaterial, preloadMaterials } from './materialCache';
import { getMaterial } from '@/bridge/commands';

const mockGetMaterial = getMaterial as unknown as Mock;

function materialData(overrides: Record<string, unknown> = {}) {
    return {
        id: 'm',
        albedo: { r: 0.5, g: 0.7, b: 0.9, a: 1 },
        roughness: 0.4,
        metallic: 0.2,
        emission: { r: 0, g: 0, b: 0, a: 1 },
        alpha: 1.0,
        shader: 'default',
        albedoTexture: null,
        clearcoat: 0,
        clearcoatRoughness: 0.05,
        normalIntensity: 1,
        useEnvironmentMap: true,
        normalPattern: null,
        surfacePattern: null,
        ...overrides,
    };
}

describe('materialCache', () => {
    beforeEach(() => {
        clearMaterialCache();
        mockGetMaterial.mockReset();
    });

    it('builds a MeshStandardMaterial with PBR fields from server', async () => {
        mockGetMaterial.mockResolvedValueOnce(materialData());

        const mat = await loadMaterial('paint');
        expect(mat).toBeInstanceOf(THREE.MeshStandardMaterial);
        expect(mat!.roughness).toBe(0.4);
        expect(mat!.metalness).toBe(0.2);
        expect(mat!.color.r).toBe(0.5);
        expect(mat!.color.g).toBe(0.7);
    });

    it('marks material as transparent when alpha < 1', async () => {
        mockGetMaterial.mockResolvedValueOnce(materialData({ alpha: 0.5 }));

        const mat = await loadMaterial('glass');
        expect(mat!.transparent).toBe(true);
        expect(mat!.opacity).toBe(0.5);
    });

    it('caches by id', async () => {
        mockGetMaterial.mockResolvedValueOnce(materialData());

        const first = await loadMaterial('paint');
        const second = await loadMaterial('paint');
        expect(first).toBe(second);
        expect(mockGetMaterial).toHaveBeenCalledTimes(1);
    });

    it('deduplicates concurrent loads', async () => {
        mockGetMaterial.mockReturnValueOnce(new Promise((resolve) => setTimeout(() => resolve(materialData()), 10)));

        const results = await Promise.all([
            loadMaterial('paint'),
            loadMaterial('paint'),
        ]);
        expect(mockGetMaterial).toHaveBeenCalledTimes(1);
        expect(results[0]).toBe(results[1]);
    });

    it('returns null when bridge rejects', async () => {
        mockGetMaterial.mockRejectedValueOnce(new Error('Unknown material'));
        const mat = await loadMaterial('ghost');
        expect(mat).toBeNull();
    });

    it('preloadMaterials populates the cache so loadMaterial serves it without a bridge call', async () => {
        preloadMaterials([materialData({ id: 'bulk' })]);

        const mat = await loadMaterial('bulk');
        expect(mat!.roughness).toBe(0.4);
        expect(mockGetMaterial).not.toHaveBeenCalled();
    });

    it('placeholderMaterial is a pink wireframe', () => {
        const mat = placeholderMaterial();
        expect(mat.wireframe).toBe(true);
        expect(mat.color.r).toBeGreaterThan(0.5);
    });

    it('clearMaterialCache disposes and resets', async () => {
        mockGetMaterial.mockResolvedValueOnce(materialData());
        const mat = await loadMaterial('paint');
        const disposeSpy = vi.spyOn(mat!, 'dispose');

        clearMaterialCache();
        expect(disposeSpy).toHaveBeenCalledOnce();

        mockGetMaterial.mockResolvedValueOnce(materialData());
        await loadMaterial('paint');
        expect(mockGetMaterial).toHaveBeenCalledTimes(2);
    });
});
