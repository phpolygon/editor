import { describe, expect, it } from 'vitest';
import {
    weldPositions,
    setGroupPosition,
    computeNormals,
    flipNormals,
    centroid,
    applyMatrixToGroups,
    type PositionGroup,
} from './editMesh';

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

describe('centroid', () => {
    const groups: PositionGroup[] = [
        { indices: [0], position: [0, 0, 0] },
        { indices: [1], position: [2, 0, 0] },
        { indices: [2], position: [2, 2, 0] },
    ];

    it('averages the positions of the selected groups', () => {
        expect(centroid(groups, [0, 1])).toEqual([1, 0, 0]);
    });

    it('ignores unknown indices and returns origin for an empty selection', () => {
        expect(centroid(groups, [])).toEqual([0, 0, 0]);
        expect(centroid(groups, [99])).toEqual([0, 0, 0]);
    });
});

describe('applyMatrixToGroups', () => {
    // Two welded groups; group 0 shares vertices 1 & 2 at the same corner.
    const verts = [0, 0, 0, 1, 0, 0, 1, 0, 0, 3, 0, 0];
    const groups: PositionGroup[] = [
        { indices: [1, 2], position: [1, 0, 0] },
        { indices: [3], position: [3, 0, 0] },
    ];

    it('translates only the selected groups, moving coincident vertices together', () => {
        // Column-major translation by (0, 5, 0).
        const translate = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 5, 0, 1];
        const out = [...verts];
        applyMatrixToGroups(verts, out, groups, [0], translate);

        expect(out.slice(3, 9)).toEqual([1, 5, 0, 1, 5, 0]); // group 0 moved
        expect(out.slice(0, 3)).toEqual([0, 0, 0]); // untouched vertex
        expect(out.slice(9, 12)).toEqual([3, 0, 0]); // group 1 not selected
    });

    it('reads from base so repeated applies are not cumulative', () => {
        const translate = [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 10, 0, 0, 1];
        const out = [...verts];
        applyMatrixToGroups(verts, out, groups, [1], translate);
        applyMatrixToGroups(verts, out, groups, [1], translate);
        expect(out.slice(9, 12)).toEqual([13, 0, 0]); // 3 + 10, not 3 + 20
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
