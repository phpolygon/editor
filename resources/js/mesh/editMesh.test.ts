import { describe, expect, it } from 'vitest';
import { weldPositions, setGroupPosition, computeNormals, flipNormals } from './editMesh';

describe('weldPositions', () => {
    it('groups duplicated corners into one entry per unique position', () => {
        // A quad as two triangles: (1,0,0) and (0,1,0) are each duplicated.
        const verts = [
            0, 0, 0, 1, 0, 0, 0, 1, 0,
            1, 0, 0, 1, 1, 0, 0, 1, 0,
        ];
        const groups = weldPositions(verts);

        expect(groups).toHaveLength(4);
        const shared = groups.find((g) => g.position[0] === 1 && g.position[1] === 0 && g.position[2] === 0);
        expect(shared?.indices.slice().sort((a, b) => a - b)).toEqual([1, 3]);
    });
});

describe('setGroupPosition', () => {
    it('moves every vertex in a welded group', () => {
        const verts = [0, 0, 0, 1, 0, 0, 1, 0, 0];
        setGroupPosition(verts, [1, 2], 5, 6, 7);
        expect(verts).toEqual([0, 0, 0, 5, 6, 7, 5, 6, 7]);
    });
});

describe('computeNormals', () => {
    it('produces the face normal for a CCW triangle in the XY plane', () => {
        const normals = computeNormals([0, 0, 0, 1, 0, 0, 0, 1, 0], [0, 1, 2]);
        for (let i = 0; i < 3; i++) {
            expect(normals[i * 3]).toBeCloseTo(0);
            expect(normals[i * 3 + 1]).toBeCloseTo(0);
            expect(normals[i * 3 + 2]).toBeCloseTo(1);
        }
    });
});

describe('flipNormals', () => {
    it('swaps winding and negates normals', () => {
        const flipped = flipNormals({
            vertices: [0, 0, 0, 1, 0, 0, 0, 1, 0],
            normals: [0, 0, 1, 0, 0, 1, 0, 0, 1],
            uvs: [],
            indices: [0, 1, 2],
        });
        expect(flipped.indices).toEqual([0, 2, 1]);
        expect(flipped.normals[2]).toBe(-1);
    });
});
