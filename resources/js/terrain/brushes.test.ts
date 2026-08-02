import { describe, it, expect } from 'vitest';
import { applyBrush, anchorAt, DEFAULT_BRUSH, type BrushSettings } from './brushes';
import { createHeightmap, heightAtWorld, type Heightmap } from './heightmap';

function terrain(level = 0.5): Heightmap {
    const map = createHeightmap({
        gridWidth: 33,
        gridDepth: 33,
        sizeX: 64,
        sizeZ: 64,
        minHeight: 0,
        maxHeight: 20,
    });
    map.samples.fill(level);
    return map;
}

function brush(overrides: Partial<BrushSettings> = {}): BrushSettings {
    return { ...DEFAULT_BRUSH, ...overrides };
}

/** Sample index nearest the terrain centre. */
function centreIndex(map: Heightmap): number {
    const cx = (map.gridWidth - 1) / 2;
    const cz = (map.gridDepth - 1) / 2;
    return cz * map.gridWidth + cx;
}

describe('terrain brushes', () => {
    it('raises samples under the brush', () => {
        const map = terrain();
        const before = map.samples[centreIndex(map)];

        const touched = applyBrush(map, {
            settings: brush({ type: 'raise', radius: 16 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: false,
        });

        expect(touched).toBe(true);
        expect(map.samples[centreIndex(map)]).toBeGreaterThan(before);
    });

    it('lowers when inverted', () => {
        const map = terrain();
        const before = map.samples[centreIndex(map)];

        applyBrush(map, {
            settings: brush({ type: 'raise', radius: 16 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: true,
        });

        expect(map.samples[centreIndex(map)]).toBeLessThan(before);
    });

    it('leaves samples outside the radius untouched', () => {
        const map = terrain();

        applyBrush(map, {
            settings: brush({ type: 'raise', radius: 8 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: false,
        });

        // Corner of a 64x64 terrain is ~45 units from the centre.
        expect(map.samples[0]).toBe(0.5);
        expect(map.samples[map.samples.length - 1]).toBe(0.5);
    });

    it('affects the centre more than the rim', () => {
        const map = terrain();

        applyBrush(map, {
            settings: brush({ type: 'raise', radius: 24, falloff: 1 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: false,
        });

        const centre = map.samples[centreIndex(map)];
        const offCentre = map.samples[centreIndex(map) + 6];

        expect(centre).toBeGreaterThan(offCentre);
    });

    it('clamps raised samples to the top of the range', () => {
        const map = terrain(0.99);

        for (let i = 0; i < 50; i++) {
            applyBrush(map, {
                settings: brush({ type: 'raise', radius: 16, strength: 1 }),
                worldX: 0,
                worldZ: 0,
                dt: 0.5,
                invert: false,
            });
        }

        expect(map.samples[centreIndex(map)]).toBe(1);
    });

    it('reports no change for a stroke off the terrain', () => {
        const map = terrain();

        const touched = applyBrush(map, {
            settings: brush({ type: 'raise', radius: 4 }),
            worldX: 500,
            worldZ: 500,
            dt: 0.1,
            invert: false,
        });

        expect(touched).toBe(false);
    });

    it('smooths a spike towards its neighbours', () => {
        const map = terrain(0.2);
        const index = centreIndex(map);
        map.samples[index] = 1;

        for (let i = 0; i < 20; i++) {
            applyBrush(map, {
                settings: brush({ type: 'smooth', radius: 16, strength: 1 }),
                worldX: 0,
                worldZ: 0,
                dt: 0.1,
                invert: false,
            });
        }

        expect(map.samples[index]).toBeLessThan(0.6);
        expect(map.samples[index]).toBeGreaterThan(0.2);
    });

    it('smooths symmetrically rather than smearing along the iteration order', () => {
        // A directional bias would show up as the +X neighbour of a spike ending
        // up higher than the -X one.
        const map = terrain(0);
        const index = centreIndex(map);
        map.samples[index] = 1;

        applyBrush(map, {
            settings: brush({ type: 'smooth', radius: 16, strength: 1 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: false,
        });

        expect(map.samples[index - 1]).toBeCloseTo(map.samples[index + 1], 6);
        expect(map.samples[index - map.gridWidth]).toBeCloseTo(map.samples[index + map.gridWidth], 6);
    });

    it('flattens towards the stroke anchor height', () => {
        const map = terrain(0.2);
        // Raise one region so there is something to level against.
        for (let i = 0; i < map.samples.length; i++) {
            if (i % map.gridWidth > 16) map.samples[i] = 0.8;
        }
        const anchor = anchorAt(map, -20, 0); // over the low side

        for (let i = 0; i < 40; i++) {
            applyBrush(map, {
                settings: brush({ type: 'flatten', radius: 40, strength: 1 }),
                worldX: 0,
                worldZ: 0,
                dt: 0.1,
                invert: false,
                anchor,
            });
        }

        expect(heightAtWorld(map, 0, 0)).toBeCloseTo(anchor.height, 1);
    });

    it('flattens towards an explicit target height', () => {
        const map = terrain(0.2);

        for (let i = 0; i < 40; i++) {
            applyBrush(map, {
                settings: brush({ type: 'flatten', radius: 40, strength: 1, targetHeight: 15 }),
                worldX: 0,
                worldZ: 0,
                dt: 0.1,
                invert: false,
            });
        }

        expect(heightAtWorld(map, 0, 0)).toBeCloseTo(15, 1);
    });

    it('roughens the surface with noise', () => {
        const map = terrain();

        applyBrush(map, {
            settings: brush({ type: 'noise', radius: 24, strength: 1 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.5,
            invert: false,
        });

        const varied = Array.from(map.samples).some((s) => s !== 0.5);
        expect(varied).toBe(true);
    });

    it('smooths instead of adding noise when inverted', () => {
        const map = terrain(0);
        const index = centreIndex(map);
        map.samples[index] = 1;

        applyBrush(map, {
            settings: brush({ type: 'noise', radius: 16, strength: 1 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.2,
            invert: true,
        });

        // A pure noise brush could not have pulled the spike down.
        expect(map.samples[index]).toBeLessThan(1);
    });

    it('ramps between the anchor and the current point', () => {
        const map = terrain(0);
        const anchor = { worldX: -20, worldZ: 0, height: 0 };
        // Raise the far end so the ramp has a gradient to interpolate.
        for (let i = 0; i < map.samples.length; i++) {
            if (i % map.gridWidth >= map.gridWidth - 3) map.samples[i] = 1;
        }

        for (let i = 0; i < 40; i++) {
            applyBrush(map, {
                settings: brush({ type: 'ramp', radius: 20, strength: 1 }),
                worldX: 30,
                worldZ: 0,
                dt: 0.1,
                invert: false,
                anchor,
            });
        }

        const low = heightAtWorld(map, -18, 0);
        const mid = heightAtWorld(map, 5, 0);
        const high = heightAtWorld(map, 28, 0);

        expect(mid).toBeGreaterThan(low);
        expect(high).toBeGreaterThan(mid);
    });

    it('does nothing for a ramp without an anchor', () => {
        const map = terrain();

        const touched = applyBrush(map, {
            settings: brush({ type: 'ramp', radius: 20 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: false,
        });

        expect(touched).toBe(false);
    });

    it('does nothing for a ramp that has not travelled', () => {
        const map = terrain();

        const touched = applyBrush(map, {
            settings: brush({ type: 'ramp', radius: 20 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.1,
            invert: false,
            anchor: { worldX: 0, worldZ: 0, height: 10 },
        });

        expect(touched).toBe(false);
    });

    it('ignores a zero radius or strength', () => {
        const map = terrain();

        expect(
            applyBrush(map, {
                settings: brush({ radius: 0 }),
                worldX: 0,
                worldZ: 0,
                dt: 0.1,
                invert: false,
            }),
        ).toBe(false);
        expect(
            applyBrush(map, {
                settings: brush({ strength: 0 }),
                worldX: 0,
                worldZ: 0,
                dt: 0.1,
                invert: false,
            }),
        ).toBe(false);
    });

    it('scales the effect with frame delta', () => {
        const slow = terrain();
        const fast = terrain();

        applyBrush(slow, {
            settings: brush({ type: 'raise', radius: 16 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.05,
            invert: false,
        });
        applyBrush(fast, {
            settings: brush({ type: 'raise', radius: 16 }),
            worldX: 0,
            worldZ: 0,
            dt: 0.2,
            invert: false,
        });

        expect(fast.samples[centreIndex(fast)]).toBeGreaterThan(slow.samples[centreIndex(slow)]);
    });
});
