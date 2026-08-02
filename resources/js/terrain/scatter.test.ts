import { describe, it, expect } from 'vitest';
import { createScatterSet, generateScatterInstances, scatterHash, type ScatterSet } from './scatter';
import { createHeightmap, type Heightmap } from './heightmap';

const OPTIONS = {
    gridWidth: 17,
    gridDepth: 17,
    sizeX: 64,
    sizeZ: 64,
    minHeight: 0,
    maxHeight: 20,
};

function terrain(fill = 0.25): Heightmap {
    const map = createHeightmap(OPTIONS);
    map.samples.fill(fill);
    return map;
}

/** A scatter set with density fully painted across the terrain. */
function fullyPainted(map: Heightmap, overrides: Partial<ScatterSet> = {}): ScatterSet {
    const set = createScatterSet('trees', 'Trees', map);
    set.density.weights.fill(255);
    set.densityPerUnit = 0.05;
    return Object.assign(set, overrides);
}

describe('new scatter sets', () => {
    it('start with no density painted, so they place nothing', () => {
        // Otherwise adding a set would instantly cover the terrain in trees and
        // the density brush could only ever remove them.
        const map = terrain();
        const set = createScatterSet('trees', 'Trees', map);

        expect(Array.from(set.density.weights).every((w) => w === 0)).toBe(true);
        expect(generateScatterInstances(set, map)).toHaveLength(0);
    });
});

describe('scatter hash parity with the engine', () => {
    it('produces the values the engine pins', () => {
        // The engine regenerates this scatter at runtime with a PHP port of
        // this hash. If the two drift, the editor previews a forest the game
        // never renders. The mirror assertion is in the engine's
        // TerrainScatterGeneratorTest::testHashMatchesTheEditorsJavascriptImplementation.
        expect(scatterHash(0, 0, 1337)).toBeCloseTo(0.12160472269169986, 15);
        expect(scatterHash(1, 0, 1337)).toBeCloseTo(0.5570077104493976, 15);
        expect(scatterHash(0, 1, 1337)).toBeCloseTo(0.035408849362283945, 15);
        expect(scatterHash(123, 4, 99)).toBeCloseTo(0.10102832107804716, 15);
        expect(scatterHash(7, 2, -9001)).toBeCloseTo(0.015377218835055828, 15);
    });

    it('stays within the unit interval', () => {
        for (let cell = 0; cell < 500; cell++) {
            const value = scatterHash(cell, cell % 5, 4242);
            expect(value).toBeGreaterThanOrEqual(0);
            expect(value).toBeLessThan(1);
        }
    });
});

describe('terrain scatter', () => {
    it('places nothing where no density is painted', () => {
        const map = terrain();
        const set = createScatterSet('trees', 'Trees', map);
        set.density.weights.fill(0);

        expect(generateScatterInstances(set, map)).toHaveLength(0);
    });

    it('places instances where density is painted', () => {
        const map = terrain();

        const instances = generateScatterInstances(fullyPainted(map), map);

        expect(instances.length).toBeGreaterThan(0);
    });

    it('is deterministic for the same seed', () => {
        const map = terrain();

        const first = generateScatterInstances(fullyPainted(map), map);
        const second = generateScatterInstances(fullyPainted(map), map);

        expect(second).toEqual(first);
    });

    it('produces a different layout for a different seed', () => {
        const map = terrain();

        const a = generateScatterInstances(fullyPainted(map, { seed: 1 }), map);
        const b = generateScatterInstances(fullyPainted(map, { seed: 2 }), map);

        expect(a).not.toEqual(b);
    });

    it('leaves distant instances untouched when the terrain is sculpted', () => {
        // The point of deriving placements from cell index + seed: sculpting
        // must re-drape the trees locally, not reshuffle the whole forest.
        // Instances *near* the edit may legitimately disappear — raising a cell
        // steepens its neighbours, and the slope rule then rejects them — so the
        // invariant is about everything outside that neighbourhood.
        const map = terrain();
        const set = fullyPainted(map);
        const before = generateScatterInstances(set, map);

        const editX = map.sizeX / 2 - map.sizeX / (map.gridWidth - 1) * 8;
        map.samples[8 * map.gridWidth + 8] = 0.9;
        const after = generateScatterInstances(set, map);

        const farFromEdit = (instance: { position: [number, number, number] }) =>
            Math.hypot(instance.position[0] - editX, instance.position[2]) > 20;

        const beforeFar = before.filter(farFromEdit);
        const afterFar = after.filter(farFromEdit);

        expect(beforeFar.length).toBeGreaterThan(50);
        expect(afterFar).toEqual(beforeFar);
    });

    it('follows the terrain height', () => {
        const low = terrain(0.1);
        const high = terrain(0.9);

        const onLow = generateScatterInstances(fullyPainted(low), low);
        const onHigh = generateScatterInstances(fullyPainted(high), high);

        expect(onLow.length).toBe(onHigh.length);
        expect(onHigh[0].position[1]).toBeGreaterThan(onLow[0].position[1]);
    });

    it('respects the height band', () => {
        const map = terrain(0.9);

        const instances = generateScatterInstances(
            fullyPainted(map, { minHeight: 0, maxHeight: 0.5 }),
            map,
        );

        expect(instances).toHaveLength(0);
    });

    it('respects the slope band', () => {
        const map = terrain();
        // A steep ramp across the whole terrain.
        for (let z = 0; z < map.gridDepth; z++) {
            for (let x = 0; x < map.gridWidth; x++) {
                map.samples[z * map.gridWidth + x] = x / (map.gridWidth - 1);
            }
        }

        const flatOnly = generateScatterInstances(
            fullyPainted(map, { minSlope: 0, maxSlope: 2 }),
            map,
        );
        const steepOk = generateScatterInstances(
            fullyPainted(map, { minSlope: 0, maxSlope: 90 }),
            map,
        );

        expect(flatOnly.length).toBeLessThan(steepOk.length);
    });

    it('scales instances within the configured range', () => {
        const map = terrain();

        const instances = generateScatterInstances(
            fullyPainted(map, { minScale: 2, maxScale: 3 }),
            map,
        );

        expect(instances.length).toBeGreaterThan(0);
        for (const instance of instances) {
            expect(instance.scale).toBeGreaterThanOrEqual(2);
            expect(instance.scale).toBeLessThanOrEqual(3);
        }
    });

    it('keeps instances upright unless aligned to the normal', () => {
        const map = terrain();
        for (let z = 0; z < map.gridDepth; z++) {
            for (let x = 0; x < map.gridWidth; x++) {
                map.samples[z * map.gridWidth + x] = x / (map.gridWidth - 1);
            }
        }

        const upright = generateScatterInstances(fullyPainted(map, { maxSlope: 90 }), map);
        const aligned = generateScatterInstances(
            fullyPainted(map, { maxSlope: 90, alignToNormal: true }),
            map,
        );

        expect(upright[0].rotation[0]).toBe(0);
        expect(upright[0].rotation[2]).toBe(0);
        expect(Math.abs(aligned[0].rotation[2])).toBeGreaterThan(0);
    });

    it('produces no instances at zero density', () => {
        const map = terrain();

        expect(generateScatterInstances(fullyPainted(map, { densityPerUnit: 0 }), map)).toHaveLength(0);
    });

    it('scales instance count with density', () => {
        const map = terrain();

        const sparse = generateScatterInstances(fullyPainted(map, { densityPerUnit: 0.01 }), map);
        const dense = generateScatterInstances(fullyPainted(map, { densityPerUnit: 0.5 }), map);

        expect(dense.length).toBeGreaterThan(sparse.length);
    });

    it('honours the instance cap', () => {
        const map = terrain();

        const capped = generateScatterInstances(fullyPainted(map, { densityPerUnit: 10 }), map, 25);

        expect(capped).toHaveLength(25);
    });

    it('keeps instances inside the terrain footprint', () => {
        const map = terrain();

        const instances = generateScatterInstances(fullyPainted(map), map);

        for (const instance of instances) {
            // Jitter can reach half a cell past the border vertices, which is
            // still within the terrain's own extents plus that half-cell.
            const marginX = map.sizeX / (map.gridWidth - 1) / 2;
            const marginZ = map.sizeZ / (map.gridDepth - 1) / 2;
            expect(Math.abs(instance.position[0])).toBeLessThanOrEqual(map.sizeX / 2 + marginX);
            expect(Math.abs(instance.position[2])).toBeLessThanOrEqual(map.sizeZ / 2 + marginZ);
        }
    });
});
