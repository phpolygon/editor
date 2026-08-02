import { describe, it, expect } from 'vitest';
import {
    bakeSplatToImage,
    clampBakeResolution,
    resolveLayerColours,
    MIN_BAKE_RESOLUTION,
    MAX_BAKE_RESOLUTION,
    type BakedImage,
} from './bakeSplat';
import { layerTint } from './layerTints';
import { createSplatMap, normaliseSplat, type SplatMap } from './splat';
import { createHeightmap, type Heightmap } from './heightmap';
import type { TerrainLayerPayload } from '@/bridge/commands';

const OPTIONS = {
    gridWidth: 9,
    gridDepth: 9,
    sizeX: 32,
    sizeZ: 32,
    minHeight: 0,
    maxHeight: 20,
};

function terrain(fill = 0.5): Heightmap {
    const map = createHeightmap(OPTIONS);
    map.samples.fill(fill);
    return map;
}

function layer(id: string, materialId = ''): TerrainLayerPayload {
    return {
        id,
        name: id,
        materialId,
        uvScale: 16,
        minHeight: 0,
        maxHeight: 1,
        minSlope: 0,
        maxSlope: 90,
    };
}

/** RGB at a pixel of the baked image. */
function pixel(image: BakedImage, x: number, y: number): [number, number, number] {
    const offset = (y * image.width + x) * 4;
    return [image.data[offset], image.data[offset + 1], image.data[offset + 2]];
}

/** A splat map where the second layer fully covers the +X half. */
function halfCovered(): SplatMap {
    const map = createSplatMap(OPTIONS, 2);
    const samples = map.gridWidth * map.gridDepth;
    for (let z = 0; z < map.gridDepth; z++) {
        for (let x = 0; x < map.gridWidth; x++) {
            if (x > map.gridWidth / 2) {
                map.weights[samples + z * map.gridWidth + x] = 255;
                map.weights[z * map.gridWidth + x] = 0;
            }
        }
    }
    normaliseSplat(map);
    return map;
}

describe('splat bake', () => {
    it('falls back to distinguishable tints for layers without a material', () => {
        const colours = resolveLayerColours([layer('grass'), layer('rock')]);

        expect(colours[0].rgb).toEqual(layerTint(0));
        expect(colours[1].rgb).toEqual(layerTint(1));
    });

    it('uses a material albedo when one can be resolved', () => {
        const colours = resolveLayerColours([layer('grass', 'mat_grass')], (id) =>
            id === 'mat_grass' ? [10, 200, 30] : null,
        );

        expect(colours[0].rgb).toEqual([10, 200, 30]);
    });

    it('falls back when the material cannot be resolved', () => {
        const colours = resolveLayerColours([layer('grass', 'missing')], () => null);

        expect(colours[0].rgb).toEqual(layerTint(0));
    });

    it('produces an opaque image at the requested resolution', () => {
        const image = bakeSplatToImage(terrain(), createSplatMap(OPTIONS, 1), [layer('grass')], {
            resolution: 128,
        });

        expect(image.width).toBe(128);
        expect(image.height).toBe(128);
        for (let i = 3; i < image.data.length; i += 4) {
            expect(image.data[i]).toBe(255);
        }
    });

    it('clamps an absurd resolution instead of allocating gigabytes', () => {
        // Tested through the pure clamp rather than by baking: exercising the
        // upper bound end-to-end would render a 4096² image (67 MB) purely to
        // check one comparison.
        expect(clampBakeResolution(1)).toBe(MIN_BAKE_RESOLUTION);
        expect(clampBakeResolution(-500)).toBe(MIN_BAKE_RESOLUTION);
        expect(clampBakeResolution(99999)).toBe(MAX_BAKE_RESOLUTION);
        expect(clampBakeResolution(512)).toBe(512);
        expect(clampBakeResolution(512.4)).toBe(512);
        expect(clampBakeResolution(Number.NaN)).toBe(1024);
    });

    it('honours the clamped resolution when baking', () => {
        const tiny = bakeSplatToImage(terrain(), createSplatMap(OPTIONS, 1), [layer('g')], {
            resolution: 1,
        });

        expect(tiny.width).toBe(MIN_BAKE_RESOLUTION);
        expect(tiny.height).toBe(MIN_BAKE_RESOLUTION);
    });

    it('paints each layer where it has coverage', () => {
        const image = bakeSplatToImage(terrain(), halfCovered(), [layer('grass'), layer('rock')], {
            resolution: 64,
            slopeShading: 0,
        });

        // Left edge is layer 0, right edge is layer 1.
        expect(pixel(image, 2, 32)).toEqual(layerTint(0));
        expect(pixel(image, 61, 32)).toEqual(layerTint(1));
    });

    it('blends smoothly across the layer boundary', () => {
        const image = bakeSplatToImage(terrain(), halfCovered(), [layer('grass'), layer('rock')], {
            resolution: 64,
            slopeShading: 0,
        });

        // Somewhere across the transition there must be a pixel that is neither
        // tint — a nearest-neighbour bake would jump straight from one to the
        // other and show the sculpt grid as hard squares.
        const blended = [];
        for (let x = 0; x < 64; x++) {
            const [r, g, b] = pixel(image, x, 32);
            const isTint0 = r === layerTint(0)[0] && g === layerTint(0)[1] && b === layerTint(0)[2];
            const isTint1 = r === layerTint(1)[0] && g === layerTint(1)[1] && b === layerTint(1)[2];
            if (!isTint0 && !isTint1) blended.push(x);
        }

        expect(blended.length).toBeGreaterThan(0);
    });

    it('bakes a neutral ground tone when there are no layers', () => {
        const image = bakeSplatToImage(terrain(), createSplatMap(OPTIONS, 0), [], {
            resolution: 64,
            slopeShading: 0,
        });

        const [r, g, b] = pixel(image, 32, 32);
        expect(r).toBeGreaterThan(0);
        expect(g).toBeGreaterThan(0);
        expect(b).toBeGreaterThan(0);
    });

    it('darkens slopes when slope shading is enabled', () => {
        // Flat on the -X half, steep on the +X half.
        const map = createHeightmap(OPTIONS);
        for (let z = 0; z < map.gridDepth; z++) {
            for (let x = 0; x < map.gridWidth; x++) {
                map.samples[z * map.gridWidth + x] = x > 4 ? (x - 4) / 4 : 0;
            }
        }
        const splat = createSplatMap(OPTIONS, 1);

        const shaded = bakeSplatToImage(map, splat, [layer('grass')], {
            resolution: 64,
            slopeShading: 0.5,
        });
        const flatPixel = pixel(shaded, 4, 32);
        const steepPixel = pixel(shaded, 48, 32);

        expect(steepPixel[0]).toBeLessThan(flatPixel[0]);
    });

    it('leaves colours untouched when slope shading is off', () => {
        const image = bakeSplatToImage(terrain(), createSplatMap(OPTIONS, 1), [layer('grass')], {
            resolution: 64,
            slopeShading: 0,
        });

        expect(pixel(image, 32, 32)).toEqual(layerTint(0));
    });

    it('ignores layers the splat map has no channel for', () => {
        // A layer list longer than the splat's channel count must not read past
        // the buffer; the extra layer simply contributes nothing.
        const image = bakeSplatToImage(
            terrain(),
            createSplatMap(OPTIONS, 1),
            [layer('grass'), layer('rock')],
            { resolution: 32, slopeShading: 0 },
        );

        expect(pixel(image, 16, 16)).toEqual(layerTint(0));
    });
});
