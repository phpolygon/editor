import { describe, it, expect } from 'vitest';
import {
    createDensityMap,
    createSplatMap,
    decodeDensityMap,
    decodeSplat,
    encodeSplat,
    resizeDensityMap,
    fillLayerByRules,
    normaliseSplat,
    paintLayer,
    resizeSplat,
    splatToVertexWeights,
    weightAt,
    type SplatMap,
} from './splat';
import { DEFAULT_BRUSH } from './brushes';
import { createHeightmap, type Heightmap } from './heightmap';

const OPTIONS = {
    gridWidth: 9,
    gridDepth: 9,
    sizeX: 32,
    sizeZ: 32,
    minHeight: 0,
    maxHeight: 20,
};

function terrain(): Heightmap {
    const map = createHeightmap(OPTIONS);
    map.samples.fill(0.5);
    return map;
}

/** Total weight across all layers at one cell — must always be 255. */
function cellTotal(map: SplatMap, x: number, z: number): number {
    let total = 0;
    for (let layer = 0; layer < map.layerCount; layer++) total += weightAt(map, layer, x, z);
    return total;
}

describe('splat maps', () => {
    it('starts fully covered by the base layer', () => {
        const map = createSplatMap(OPTIONS, 3);

        expect(weightAt(map, 0, 4, 4)).toBe(255);
        expect(weightAt(map, 1, 4, 4)).toBe(0);
        expect(cellTotal(map, 4, 4)).toBe(255);
    });

    it('handles a terrain with no layers', () => {
        const map = createSplatMap(OPTIONS, 0);

        expect(map.weights).toHaveLength(0);
        expect(encodeSplat(map)).toBe('');
        expect(weightAt(map, 0, 0, 0)).toBe(0);
    });

    it('round-trips through base64', () => {
        const map = createSplatMap(OPTIONS, 2);
        map.weights[10] = 42;

        const restored = decodeSplat(encodeSplat(map), OPTIONS, 2);

        expect(Array.from(restored.weights)).toEqual(Array.from(map.weights));
    });

    it('falls back to a fresh map for a payload that does not fit', () => {
        const map = createSplatMap(OPTIONS, 2);

        const restored = decodeSplat(encodeSplat(map), OPTIONS, 3);

        expect(restored.layerCount).toBe(3);
        expect(weightAt(restored, 0, 0, 0)).toBe(255);
    });

    it('paints coverage under the brush', () => {
        const map = createSplatMap(OPTIONS, 2);

        const touched = paintLayer(map, terrain(), {
            layer: 1,
            settings: { ...DEFAULT_BRUSH, radius: 12, strength: 1 },
            worldX: 0,
            worldZ: 0,
            dt: 0.5,
            erase: false,
        });

        expect(touched).toBe(true);
        expect(weightAt(map, 1, 4, 4)).toBeGreaterThan(0);
    });

    it('keeps every painted cell summing to 255', () => {
        const map = createSplatMap(OPTIONS, 3);
        const heightmap = terrain();

        for (let i = 0; i < 5; i++) {
            paintLayer(map, heightmap, {
                layer: 1,
                settings: { ...DEFAULT_BRUSH, radius: 14, strength: 1 },
                worldX: 0,
                worldZ: 0,
                dt: 0.2,
                erase: false,
            });
            paintLayer(map, heightmap, {
                layer: 2,
                settings: { ...DEFAULT_BRUSH, radius: 10, strength: 0.7 },
                worldX: 4,
                worldZ: 4,
                dt: 0.2,
                erase: false,
            });
        }

        for (let z = 0; z < 9; z++) {
            for (let x = 0; x < 9; x++) {
                expect(cellTotal(map, x, z), `cell ${x},${z}`).toBe(255);
            }
        }
    });

    it('erases coverage when inverted', () => {
        const map = createSplatMap(OPTIONS, 2);
        const heightmap = terrain();
        const paint = (erase: boolean) =>
            paintLayer(map, heightmap, {
                layer: 1,
                settings: { ...DEFAULT_BRUSH, radius: 14, strength: 1 },
                worldX: 0,
                worldZ: 0,
                dt: 0.5,
                erase,
            });

        paint(false);
        const painted = weightAt(map, 1, 4, 4);
        paint(true);

        expect(weightAt(map, 1, 4, 4)).toBeLessThan(painted);
    });

    it('leaves cells outside the brush untouched', () => {
        const map = createSplatMap(OPTIONS, 2);

        paintLayer(map, terrain(), {
            layer: 1,
            settings: { ...DEFAULT_BRUSH, radius: 4, strength: 1 },
            worldX: 0,
            worldZ: 0,
            dt: 0.5,
            erase: false,
        });

        expect(weightAt(map, 1, 0, 0)).toBe(0);
        expect(weightAt(map, 0, 0, 0)).toBe(255);
    });

    it('rejects painting a layer that does not exist', () => {
        const map = createSplatMap(OPTIONS, 1);

        const touched = paintLayer(map, terrain(), {
            layer: 5,
            settings: DEFAULT_BRUSH,
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            erase: false,
        });

        expect(touched).toBe(false);
    });

    it('normalises an empty cell back to the base layer', () => {
        const map = createSplatMap(OPTIONS, 2);
        map.weights.fill(0);

        normaliseSplat(map);

        expect(weightAt(map, 0, 3, 3)).toBe(255);
        expect(cellTotal(map, 3, 3)).toBe(255);
    });

    it('fills a layer from its slope rules', () => {
        const heightmap = createHeightmap(OPTIONS);
        // Steep on the +X half, flat on the -X half.
        for (let z = 0; z < 9; z++) {
            for (let x = 0; x < 9; x++) {
                heightmap.samples[z * 9 + x] = x > 4 ? (x - 4) / 4 : 0;
            }
        }
        const map = createSplatMap(OPTIONS, 2);

        fillLayerByRules(map, heightmap, 1, {
            minHeight: 0,
            maxHeight: 1,
            minSlope: 20,
            maxSlope: 90,
        });

        expect(weightAt(map, 1, 0, 4)).toBe(0);
        expect(weightAt(map, 1, 6, 4)).toBe(255);
    });

    it('fills a layer from its height rules', () => {
        const heightmap = createHeightmap(OPTIONS);
        for (let i = 0; i < heightmap.samples.length; i++) {
            heightmap.samples[i] = i < 40 ? 0.1 : 0.9;
        }
        const map = createSplatMap(OPTIONS, 2);

        fillLayerByRules(map, heightmap, 1, {
            minHeight: 0.5,
            maxHeight: 1,
            minSlope: 0,
            maxSlope: 90,
        });

        expect(map.weights[map.gridWidth * map.gridDepth + 0]).toBe(0);
        expect(map.weights[map.gridWidth * map.gridDepth + 80]).toBe(255);
    });

    it('preserves coverage when resizing the grid', () => {
        const map = createSplatMap(OPTIONS, 2);
        map.weights.fill(255, map.gridWidth * map.gridDepth); // layer 1 fully covered
        normaliseSplat(map);

        const bigger = resizeSplat(map, { ...OPTIONS, gridWidth: 17, gridDepth: 17 }, 2);

        expect(bigger.gridWidth).toBe(17);
        expect(weightAt(bigger, 1, 8, 8)).toBeGreaterThan(0);
        expect(cellTotal(bigger, 8, 8)).toBe(255);
    });

    it('drops the removed channel when a layer is deleted', () => {
        const map = createSplatMap(OPTIONS, 3);
        const samples = map.gridWidth * map.gridDepth;
        map.weights.fill(0);
        map.weights.fill(255, samples * 2, samples * 3); // only layer 2 covered

        const reduced = resizeSplat(map, OPTIONS, 2, 1);

        // Old layer 2 must survive as the new layer 1, not be discarded.
        expect(reduced.layerCount).toBe(2);
        expect(weightAt(reduced, 1, 4, 4)).toBe(255);
    });

    it('starts a density map empty, not fully covered', () => {
        // A splat map's base layer starts at full coverage so terrain is never
        // untextured. A density map means the opposite — full coverage would
        // drop a forest over the whole terrain the moment a set is added.
        const density = createDensityMap(OPTIONS);

        expect(density.layerCount).toBe(1);
        expect(Array.from(density.weights).every((w) => w === 0)).toBe(true);
    });

    it('keeps a density map empty across a resize', () => {
        const density = createDensityMap(OPTIONS);

        const resized = resizeDensityMap(density, { ...OPTIONS, gridWidth: 17, gridDepth: 17 });

        expect(resized.gridWidth).toBe(17);
        expect(Array.from(resized.weights).every((w) => w === 0)).toBe(true);
    });

    it('preserves painted density across a resize', () => {
        const density = createDensityMap(OPTIONS);
        density.weights.fill(200);

        const resized = resizeDensityMap(density, { ...OPTIONS, gridWidth: 17, gridDepth: 17 });

        expect(Array.from(resized.weights).every((w) => w === 200)).toBe(true);
    });

    it('decodes a missing density payload as empty', () => {
        const density = decodeDensityMap('', OPTIONS);

        expect(Array.from(density.weights).every((w) => w === 0)).toBe(true);
    });

    it('round-trips a painted density payload', () => {
        const density = createDensityMap(OPTIONS);
        density.weights[5] = 128;

        const restored = decodeDensityMap(encodeSplat(density), OPTIONS);

        expect(Array.from(restored.weights)).toEqual(Array.from(density.weights));
    });

    it('paints density without the base-layer normalisation kicking in', () => {
        // paintLayer is shared with splat painting, whose normalisation would
        // fight a single-channel density map.
        const density = createDensityMap(OPTIONS);

        paintLayer(density, terrain(), {
            layer: 0,
            settings: { ...DEFAULT_BRUSH, radius: 12, strength: 1 },
            worldX: 0,
            worldZ: 0,
            dt: 0.5,
            erase: false,
        });

        expect(weightAt(density, 0, 4, 4)).toBeGreaterThan(0);
        expect(weightAt(density, 0, 0, 0)).toBe(0);
    });

    it('exposes per-vertex weights normalised to 0..1', () => {
        const map = createSplatMap(OPTIONS, 2);

        const weights = splatToVertexWeights(map);

        expect(weights).toHaveLength(9 * 9 * 2);
        expect(weights[0]).toBe(1);
        expect(weights[1]).toBe(0);
    });
});
