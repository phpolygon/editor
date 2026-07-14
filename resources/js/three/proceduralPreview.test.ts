import { describe, expect, it, beforeEach, vi, type Mock } from 'vitest';

vi.mock('@/bridge/commands', () => ({
    evaluateProceduralMesh: vi.fn(),
    getMesh: vi.fn(),
}));

import { previewGraph, previewProceduralMesh } from './proceduralPreview';
import { clearMeshCache, loadMesh } from './meshCache';
import { evaluateProceduralMesh } from '@/bridge/commands';

const mockEvaluate = evaluateProceduralMesh as unknown as Mock;

function meshDto(version = 1) {
    return {
        id: 'proc',
        version,
        vertices: [0, 0, 0, 1, 0, 0, 0, 1, 0],
        normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
        uvs: [0, 0, 1, 0, 0, 1],
        indices: [0, 1, 2],
        vertexCount: 3,
        triangleCount: 1,
    };
}

describe('previewProceduralMesh', () => {
    beforeEach(() => {
        clearMeshCache();
        mockEvaluate.mockReset();
    });

    it('builds a geometry from the evaluated mesh', async () => {
        mockEvaluate.mockResolvedValue(meshDto());
        const geom = await previewProceduralMesh([{ id: 'b', type: 'box' }], 'b');

        expect(geom).not.toBeNull();
        expect(geom!.getAttribute('position').count).toBe(3);
        expect(mockEvaluate).toHaveBeenCalledWith([{ id: 'b', type: 'box' }], 'b', '');
    });

    it('caches under meshId so loadMesh returns the same geometry', async () => {
        mockEvaluate.mockResolvedValue({ ...meshDto(), id: 'tree' });
        const geom = await previewProceduralMesh([{ id: 'b', type: 'box' }], 'b', 'tree');

        const fetched = await loadMesh('tree');
        expect(fetched).toBe(geom);
    });

    it('returns null when evaluation fails', async () => {
        mockEvaluate.mockRejectedValue(new Error('cycle'));
        const geom = await previewProceduralMesh([], '');
        expect(geom).toBeNull();
    });

    it('previewGraph forwards nodes/output/meshId', async () => {
        mockEvaluate.mockResolvedValue({ ...meshDto(), id: 'g' });
        await previewGraph({ nodes: [{ id: 'b', type: 'box' }], output: 'b', meshId: 'g' });
        expect(mockEvaluate).toHaveBeenCalledWith([{ id: 'b', type: 'box' }], 'b', 'g');
    });
});
