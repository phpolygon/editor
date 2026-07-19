import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';
import * as THREE from 'three';

vi.mock('@/bridge/commands', () => ({
    getMesh: vi.fn(),
    getMaterial: vi.fn(),
    assetFileUrl: (p: string) => `/test-assets/${p}`,
}));

import { loadMesh, clearMeshCache, placeholderGeometry, preloadMeshes } from './meshCache';
import { getMesh } from '@/bridge/commands';

const mockGetMesh = getMesh as unknown as Mock;

function meshData(overrides: Partial<{ vertices: number[]; normals: number[]; uvs: number[]; indices: number[]; version: number }> = {}) {
    return {
        id: 'm',
        version: 1,
        vertices: [0, 0, 0, 1, 0, 0, 0, 1, 0],
        normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
        uvs: [0, 0, 1, 0, 0, 1],
        indices: [0, 1, 2],
        vertexCount: 3,
        triangleCount: 1,
        ...overrides,
    };
}

describe('meshCache', () => {
    beforeEach(() => {
        clearMeshCache();
        mockGetMesh.mockReset();
    });

    it('builds a BufferGeometry from server response', async () => {
        mockGetMesh.mockResolvedValueOnce(meshData());

        const geom = await loadMesh('cube');
        expect(geom).toBeInstanceOf(THREE.BufferGeometry);
        expect(geom!.getAttribute('position').count).toBe(3);
        expect(geom!.getIndex()!.count).toBe(3);
    });

    it('caches by id (second call does not hit bridge)', async () => {
        mockGetMesh.mockResolvedValueOnce(meshData());

        await loadMesh('cube');
        await loadMesh('cube');
        expect(mockGetMesh).toHaveBeenCalledTimes(1);
    });

    it('deduplicates concurrent loads of the same id', async () => {
        mockGetMesh.mockReturnValueOnce(new Promise((resolve) => setTimeout(() => resolve(meshData()), 10)));

        const [a, b, c] = await Promise.all([
            loadMesh('cube'),
            loadMesh('cube'),
            loadMesh('cube'),
        ]);
        expect(mockGetMesh).toHaveBeenCalledTimes(1);
        expect(a).toBe(b);
        expect(b).toBe(c);
    });

    it('returns null when the bridge rejects (mesh not found)', async () => {
        mockGetMesh.mockRejectedValueOnce(new Error('Unknown mesh: ghost'));

        const geom = await loadMesh('ghost');
        expect(geom).toBeNull();
    });

    it('allows retrying after a failed load', async () => {
        mockGetMesh.mockRejectedValueOnce(new Error('fail'));
        mockGetMesh.mockResolvedValueOnce(meshData());

        const first = await loadMesh('flaky');
        const second = await loadMesh('flaky');
        expect(first).toBeNull();
        expect(second).toBeInstanceOf(THREE.BufferGeometry);
        expect(mockGetMesh).toHaveBeenCalledTimes(2);
    });

    it('computes normals when the response omits them', async () => {
        mockGetMesh.mockResolvedValueOnce(meshData({ normals: [] }));

        const geom = await loadMesh('no-normals');
        expect(geom!.getAttribute('normal')).toBeDefined();
        expect(geom!.getAttribute('normal').count).toBe(3);
    });

    it('clearMeshCache disposes geometries and resets state', async () => {
        mockGetMesh.mockResolvedValueOnce(meshData());
        const first = await loadMesh('cube');
        const disposeSpy = vi.spyOn(first!, 'dispose');

        clearMeshCache();
        expect(disposeSpy).toHaveBeenCalledOnce();

        // After clear, a fresh load should re-fetch
        mockGetMesh.mockResolvedValueOnce(meshData());
        await loadMesh('cube');
        expect(mockGetMesh).toHaveBeenCalledTimes(2);
    });

    it('preloadMeshes populates the cache so loadMesh serves it without a bridge call', async () => {
        preloadMeshes([{ ...meshData(), id: 'bulk' }]);

        const geom = await loadMesh('bulk');
        expect(geom).toBeInstanceOf(THREE.BufferGeometry);
        expect(geom!.getAttribute('position').count).toBe(3);
        expect(mockGetMesh).not.toHaveBeenCalled();
    });

    it('placeholderGeometry returns a unit BoxGeometry', () => {
        const geom = placeholderGeometry();
        expect(geom).toBeInstanceOf(THREE.BoxGeometry);
    });
});
