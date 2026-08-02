import { describe, it, expect } from 'vitest';
import rawFixture from '../../../tests/fixtures/terrain-geometry.json';
import { heightmapFromEncoded } from './heightmap';
import { buildRegion, buildTerrainGeometry } from './terrainMesh';

/**
 * Parity between the editor's preview mesh builder and the engine's.
 *
 * The editor sculpts against TypeScript-built geometry while the game renders
 * PHP-built geometry. If the two drift, an artist shapes terrain the engine
 * will not reproduce — a class of bug that is invisible in the editor and only
 * shows up in the running game.
 *
 * The fixture is generated from the *engine* by
 * `scripts/generate-terrain-fixture.php`, so this test measures the TypeScript
 * side against the real thing rather than against another copy of the same
 * assumptions. `TerrainMeshBuilderFixtureTest` pins the PHP side to the same
 * file, so drift on either side fails.
 */

interface GeometryFixture {
    positions: number[];
    normals: number[];
    uvs: number[];
    indices: number[];
}

interface TerrainFixture {
    heightmap: {
        gridWidth: number;
        gridDepth: number;
        sizeX: number;
        sizeZ: number;
        minHeight: number;
        maxHeight: number;
        heights: string;
    };
    single: GeometryFixture;
    chunks: (GeometryFixture & { chunkX: number; chunkZ: number; meshId: string })[];
}

const fixture = rawFixture as unknown as TerrainFixture;

const heightmap = heightmapFromEncoded(fixture.heightmap, fixture.heightmap.heights);

/**
 * Compared to 4 decimal places, which covers only float32 rounding: the editor
 * stores samples and buffers in Float32Arrays where PHP uses doubles. A larger
 * difference than that is a genuine geometry mismatch, not a precision
 * artefact.
 */
function expectClose(actual: ArrayLike<number>, expected: number[], label: string) {
    expect(actual.length, `${label}: length`).toBe(expected.length);
    for (let i = 0; i < expected.length; i++) {
        expect(actual[i], `${label}[${i}]`).toBeCloseTo(expected[i], 4);
    }
}

describe('engine parity', () => {
    it('decodes the engine heightmap payload', () => {
        expect(heightmap.gridWidth).toBe(9);
        expect(heightmap.gridDepth).toBe(5);
        expect(heightmap.samples).toHaveLength(45);
    });

    it('builds the same single mesh as the engine', () => {
        const geometry = buildTerrainGeometry(heightmap);

        expectClose(geometry.positions, fixture.single.positions, 'positions');
        expectClose(geometry.normals, fixture.single.normals, 'normals');
        expectClose(geometry.uvs, fixture.single.uvs, 'uvs');
        expect(Array.from(geometry.indices)).toEqual(fixture.single.indices);
    });

    it('builds the same chunk meshes as the engine', () => {
        // Mirrors TerrainMeshBuilder::buildChunks with chunkSize 4: chunk bounds
        // are inclusive vertex coordinates, and the last chunk is clamped to the
        // grid rather than overrunning it.
        const chunkSize = 4;
        const quadsX = heightmap.gridWidth - 1;
        const quadsZ = heightmap.gridDepth - 1;

        expect(fixture.chunks.length).toBeGreaterThan(0);

        for (const expected of fixture.chunks) {
            const x0 = expected.chunkX * chunkSize;
            const z0 = expected.chunkZ * chunkSize;
            const x1 = Math.min(x0 + chunkSize, quadsX);
            const z1 = Math.min(z0 + chunkSize, quadsZ);

            const geometry = buildRegion(heightmap, x0, z0, x1, z1);
            const label = `chunk ${expected.chunkX},${expected.chunkZ}`;

            expectClose(geometry.positions, expected.positions, `${label} positions`);
            expectClose(geometry.normals, expected.normals, `${label} normals`);
            expectClose(geometry.uvs, expected.uvs, `${label} uvs`);
            expect(Array.from(geometry.indices), `${label} indices`).toEqual(expected.indices);
        }
    });

    it('keeps chunk vertices within the engine tolerance of the single mesh', () => {
        // Cross-check independent of the fixture: a chunk's shared vertices must
        // equal the corresponding ones of the full mesh, or chunk borders crack.
        const single = buildTerrainGeometry(heightmap);
        const chunk = buildRegion(heightmap, 4, 0, 8, 4);

        for (let z = 0; z <= 4; z++) {
            for (let x = 0; x <= 4; x++) {
                const chunkIndex = (z * 5 + x) * 3;
                const singleIndex = (z * heightmap.gridWidth + (x + 4)) * 3;

                expect(chunk.positions[chunkIndex]).toBeCloseTo(single.positions[singleIndex], 5);
                expect(chunk.positions[chunkIndex + 1]).toBeCloseTo(single.positions[singleIndex + 1], 5);
                expect(chunk.normals[chunkIndex + 1]).toBeCloseTo(single.normals[singleIndex + 1], 5);
            }
        }
    });

    it('keeps the encoded payload byte-identical after a decode/encode round trip', async () => {
        const { encodeHeights } = await import('./heightmap');

        // Quantisation is lossless through a round trip, which is what lets the
        // editor hand a terrain straight back to the engine without resampling.
        expect(encodeHeights(heightmap.samples)).toBe(fixture.heightmap.heights);
    });
});
