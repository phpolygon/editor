import { describe, it, expect } from 'vitest';
import { createHeightmap, heightAt, normalAt, type Heightmap } from './heightmap';
import {
    buildRegion,
    buildTerrainGeometry,
    geometryMatches,
    raycastTerrain,
    updateTerrainGeometry,
} from './terrainMesh';

function ramp(gridWidth = 9, gridDepth = 9): Heightmap {
    const map = createHeightmap({
        gridWidth,
        gridDepth,
        sizeX: 16,
        sizeZ: 16,
        minHeight: -10,
        maxHeight: 10,
    });
    for (let z = 0; z < gridDepth; z++) {
        for (let x = 0; x < gridWidth; x++) {
            map.samples[z * gridWidth + x] = x / (gridWidth - 1);
        }
    }
    return map;
}

describe('terrain mesh', () => {
    it('emits one vertex per grid sample', () => {
        const geometry = buildTerrainGeometry(createHeightmap({ gridWidth: 5, gridDepth: 4 }));

        expect(geometry.vertexCount).toBe(20);
        expect(geometry.triangleCount).toBe(24);
        expect(geometry.positions).toHaveLength(60);
        expect(geometry.uvs).toHaveLength(40);
        expect(geometry.indices).toHaveLength(72);
    });

    it('places vertices at the heightmap positions', () => {
        const map = ramp(2, 2);

        const geometry = buildTerrainGeometry(map);

        // Vertex 0 is grid (0,0); vertex 3 is grid (1,1).
        expect(Array.from(geometry.positions.slice(0, 3))).toEqual([-8, -10, -8]);
        expect(Array.from(geometry.positions.slice(9, 12))).toEqual([8, 10, 8]);
    });

    it('spans uvs 0..1 across the whole terrain', () => {
        const geometry = buildTerrainGeometry(createHeightmap({ gridWidth: 3, gridDepth: 3 }));

        expect(Array.from(geometry.uvs.slice(0, 2))).toEqual([0, 0]);
        expect(Array.from(geometry.uvs.slice(-2))).toEqual([1, 1]);
    });

    it('keeps region vertices in terrain space', () => {
        const map = ramp();

        const second = buildRegion(map, 4, 0, 8, 4);

        // The region starting at grid column 4 begins at the terrain midpoint,
        // not at its own origin.
        expect(second.positions[0]).toBe(0);
        expect(second.vertexCount).toBe(25);
    });

    it('gives adjacent regions identical shared-edge vertices', () => {
        const map = ramp();

        const left = buildRegion(map, 0, 0, 4, 4);
        const right = buildRegion(map, 4, 0, 8, 4);

        for (let row = 0; row < 5; row++) {
            const leftEdge = (row * 5 + 4) * 3;
            const rightEdge = (row * 5 + 0) * 3;

            expect(Array.from(left.positions.slice(leftEdge, leftEdge + 3))).toEqual(
                Array.from(right.positions.slice(rightEdge, rightEdge + 3)),
            );
            expect(Array.from(left.normals.slice(leftEdge, leftEdge + 3))).toEqual(
                Array.from(right.normals.slice(rightEdge, rightEdge + 3)),
            );
        }
    });

    it('winds triangles counter-clockwise seen from above', () => {
        const geometry = buildTerrainGeometry(createHeightmap({ gridWidth: 2, gridDepth: 2 }));
        const vertex = (i: number) => [
            geometry.positions[i * 3],
            geometry.positions[i * 3 + 1],
            geometry.positions[i * 3 + 2],
        ];

        const a = vertex(geometry.indices[0]);
        const b = vertex(geometry.indices[1]);
        const c = vertex(geometry.indices[2]);
        const ab = [b[0] - a[0], b[1] - a[1], b[2] - a[2]];
        const ac = [c[0] - a[0], c[1] - a[1], c[2] - a[2]];

        expect(ab[2] * ac[0] - ab[0] * ac[2]).toBeGreaterThan(0);
    });

    it('uses 32-bit indices so large terrains are representable', () => {
        const geometry = buildTerrainGeometry(createHeightmap({ gridWidth: 3, gridDepth: 3 }));

        expect(geometry.indices).toBeInstanceOf(Uint32Array);
    });

    it('refreshes positions and normals in place', () => {
        const map = ramp(5, 5);
        const geometry = buildTerrainGeometry(map);
        const positions = geometry.positions;

        map.samples.fill(1);
        updateTerrainGeometry(map, geometry);

        // The same buffer must be reused so the viewport can flag it for
        // re-upload instead of rebuilding the BufferGeometry.
        expect(geometry.positions).toBe(positions);
        expect(geometry.positions[1]).toBe(heightAt(map, 0, 0));
        expect(Array.from(geometry.normals.slice(0, 3))).toEqual(normalAt(map, 0, 0));
    });

    it('detects when a geometry no longer matches the grid', () => {
        const map = ramp(5, 5);
        const geometry = buildTerrainGeometry(map);

        expect(geometryMatches(map, geometry)).toBe(true);
        expect(geometryMatches(ramp(9, 9), geometry)).toBe(false);
        expect(geometryMatches(map, null)).toBe(false);
    });
});

describe('terrain raycast', () => {
    it('hits flat terrain under the ray', () => {
        const map = createHeightmap({
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 64,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 10,
        });
        map.samples.fill(0.5); // world height 5

        const hit = raycastTerrain(map, [0, 50, 0], [0, -1, 0]);

        expect(hit).not.toBeNull();
        expect(hit![0]).toBeCloseTo(0, 3);
        expect(hit![1]).toBeCloseTo(5, 2);
        expect(hit![2]).toBeCloseTo(0, 3);
    });

    it('hits a slope at the right place', () => {
        const map = ramp(33, 33);

        const hit = raycastTerrain(map, [4, 60, 0], [0, -1, 0]);

        expect(hit).not.toBeNull();
        expect(hit![0]).toBeCloseTo(4, 3);
        // The ramp runs -10..10 over x in -8..8, so x=4 sits at y=5.
        expect(hit![1]).toBeCloseTo(5, 1);
    });

    it('misses when the ray passes beside the terrain', () => {
        const map = createHeightmap({ gridWidth: 9, gridDepth: 9, sizeX: 64, sizeZ: 64 });

        expect(raycastTerrain(map, [500, 50, 500], [0, -1, 0])).toBeNull();
    });

    it('misses when the ray points away from the terrain', () => {
        const map = createHeightmap({ gridWidth: 9, gridDepth: 9, sizeX: 64, sizeZ: 64 });

        expect(raycastTerrain(map, [0, 50, 0], [0, 1, 0])).toBeNull();
    });

    it('returns nothing when the origin is already underground', () => {
        const map = createHeightmap({
            gridWidth: 9,
            gridDepth: 9,
            sizeX: 64,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 10,
        });
        map.samples.fill(1);

        expect(raycastTerrain(map, [0, 2, 0], [0, -1, 0])).toBeNull();
    });

    it('hits from an angled ray', () => {
        const map = createHeightmap({
            gridWidth: 17,
            gridDepth: 17,
            sizeX: 64,
            sizeZ: 64,
            minHeight: 0,
            maxHeight: 10,
        });
        map.samples.fill(0);

        const d = 1 / Math.sqrt(2);
        const hit = raycastTerrain(map, [-20, 20, 0], [d, -d, 0]);

        expect(hit).not.toBeNull();
        expect(hit![1]).toBeCloseTo(0, 1);
        expect(hit![0]).toBeCloseTo(0, 1);
    });
});
