import { describe, it, expect } from 'vitest';
import {
    createHeightmap,
    cloneHeightmap,
    decodeHeights,
    encodeHeights,
    heightAt,
    heightAtWorld,
    heightmapFromEncoded,
    normalAt,
    reprojectRange,
    resample,
    sampleAt,
    sampleExtent,
    slopeAt,
    worldX,
    worldZ,
} from './heightmap';

function ramp(gridWidth = 3, gridDepth = 3) {
    const map = createHeightmap({
        gridWidth,
        gridDepth,
        sizeX: 20,
        sizeZ: 20,
        minHeight: 0,
        maxHeight: 10,
    });
    for (let z = 0; z < gridDepth; z++) {
        for (let x = 0; x < gridWidth; x++) {
            map.samples[z * gridWidth + x] = x / (gridWidth - 1);
        }
    }
    return map;
}

describe('heightmap', () => {
    it('maps normalised samples into the height range', () => {
        const map = createHeightmap({ gridWidth: 2, gridDepth: 2, minHeight: -20, maxHeight: 80 });
        map.samples.set([0, 0.5, 1, 0.25]);

        expect(heightAt(map, 0, 0)).toBe(-20);
        expect(heightAt(map, 1, 0)).toBe(30);
        expect(heightAt(map, 0, 1)).toBe(80);
        expect(heightAt(map, 1, 1)).toBe(5);
    });

    it('centres the grid on the origin', () => {
        const map = createHeightmap({ gridWidth: 5, gridDepth: 5, sizeX: 100, sizeZ: 40 });

        expect(worldX(map, 0)).toBe(-50);
        expect(worldX(map, 4)).toBe(50);
        expect(worldZ(map, 0)).toBe(-20);
        expect(worldZ(map, 4)).toBe(20);
    });

    it('clamps sample coordinates at the border', () => {
        const map = ramp();

        expect(sampleAt(map, -5, -5)).toBe(sampleAt(map, 0, 0));
        expect(sampleAt(map, 99, 99)).toBe(sampleAt(map, 2, 2));
    });

    it('interpolates world heights bilinearly', () => {
        const map = ramp(2, 2);

        expect(heightAtWorld(map, -10, 0)).toBeCloseTo(0, 9);
        expect(heightAtWorld(map, 0, 0)).toBeCloseTo(5, 9);
        expect(heightAtWorld(map, 10, 0)).toBeCloseTo(10, 9);
    });

    it('gives flat terrain a straight-up normal', () => {
        const map = createHeightmap({ gridWidth: 5, gridDepth: 5 });

        // Component-wise: a zero slope yields -0 for the X/Z terms in JS, which
        // is numerically 0 but not Object.is-equal to it.
        const [nx, ny, nz] = normalAt(map, 2, 2);
        expect(nx).toBeCloseTo(0, 9);
        expect(ny).toBe(1);
        expect(nz).toBeCloseTo(0, 9);
        expect(slopeAt(map, 2, 2)).toBeCloseTo(0, 9);
    });

    it('tilts the normal against the slope direction', () => {
        const map = ramp();

        const [nx, ny, nz] = normalAt(map, 1, 1);

        expect(nx).toBeLessThan(0);
        expect(ny).toBeGreaterThan(0);
        expect(nz).toBeCloseTo(0, 9);
        expect(Math.hypot(nx, ny, nz)).toBeCloseTo(1, 9);
        expect(slopeAt(map, 1, 1)).toBeGreaterThan(0);
    });

    it('round-trips the encoded payload within quantisation error', () => {
        const map = ramp(8, 8);

        const restored = decodeHeights(encodeHeights(map.samples), 64);

        expect(restored).not.toBeNull();
        for (let i = 0; i < map.samples.length; i++) {
            expect(restored![i]).toBeCloseTo(map.samples[i], 4);
        }
    });

    it('produces the byte layout the engine expects', () => {
        // Little-endian uint16: 0 -> 0x0000, 1 -> 0xFFFF, 0.5 -> 0x8000.
        const samples = Float32Array.from([0, 1, 0.5]);

        const bytes = atob(encodeHeights(samples));

        expect(bytes.length).toBe(6);
        expect([bytes.charCodeAt(0), bytes.charCodeAt(1)]).toEqual([0x00, 0x00]);
        expect([bytes.charCodeAt(2), bytes.charCodeAt(3)]).toEqual([0xff, 0xff]);
        expect([bytes.charCodeAt(4), bytes.charCodeAt(5)]).toEqual([0x00, 0x80]);
    });

    it('clamps out-of-range samples when encoding', () => {
        const bytes = atob(encodeHeights(Float32Array.from([-3, 7])));

        expect([bytes.charCodeAt(0), bytes.charCodeAt(1)]).toEqual([0x00, 0x00]);
        expect([bytes.charCodeAt(2), bytes.charCodeAt(3)]).toEqual([0xff, 0xff]);
    });

    it('rejects a payload that does not match the grid', () => {
        const map = ramp(4, 4);

        expect(decodeHeights(encodeHeights(map.samples), 64)).toBeNull();
        expect(decodeHeights('', 16)).toBeNull();
    });

    it('falls back to flat terrain for an unusable payload', () => {
        const map = heightmapFromEncoded(
            { gridWidth: 4, gridDepth: 4, sizeX: 10, sizeZ: 10, minHeight: 5, maxHeight: 25 },
            'not-valid-base64!!',
        );

        expect(map.samples).toHaveLength(16);
        expect(heightAt(map, 2, 2)).toBe(5);
    });

    it('keeps the sculpt shape when the height range changes', () => {
        const map = ramp(3, 3);
        const before = heightAtWorld(map, 0, 0);

        const rescaled = reprojectRange(map, 0, 40);

        expect(rescaled.maxHeight).toBe(40);
        expect(heightAtWorld(rescaled, 0, 0)).toBeCloseTo(before, 5);
    });

    it('ignores an inverted height range', () => {
        const map = ramp();

        expect(reprojectRange(map, 50, 10)).toBe(map);
    });

    it('preserves the shape when resampling to a finer grid', () => {
        const map = ramp(3, 3);

        const finer = resample(map, 5, 5);

        expect(finer.gridWidth).toBe(5);
        expect(finer.samples).toHaveLength(25);
        // The ramp's midpoint height must survive the resample.
        expect(heightAtWorld(finer, 0, 0)).toBeCloseTo(heightAtWorld(map, 0, 0), 5);
    });

    it('reports the sample extent', () => {
        const map = ramp();

        expect(sampleExtent(map)).toEqual([0, 1]);
    });

    it('clones without sharing the sample buffer', () => {
        const map = ramp();
        const copy = cloneHeightmap(map);

        copy.samples[0] = 0.9;

        expect(map.samples[0]).toBe(0);
    });
});
