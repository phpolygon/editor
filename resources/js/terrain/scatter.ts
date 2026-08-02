/**
 * Foliage / object scattering over a terrain.
 *
 * Placements are **derived, never stored**. The asset holds a painted density
 * map, a seed and a set of rules; instances are regenerated from those. That is
 * what keeps scatter stable under editing: because a candidate's position and
 * random values depend only on its cell index and the seed, sculpting a hill
 * re-drapes the trees already standing on it rather than reshuffling every tree
 * on the map. It also keeps the asset small — one byte per grid cell instead of
 * a transform per instance.
 *
 * Generation is deterministic in the strict sense: same seed plus same density
 * map always yields the same instances, in the same order.
 */

import {
    type Heightmap,
    heightAt,
    normalAt,
    slopeAt,
    worldX,
    worldZ,
} from './heightmap';
import { createDensityMap, sampleCountOf, type SplatMap } from './splat';

export interface ScatterSet {
    id: string;
    name: string;
    /** Mesh registered in the project, drawn for each instance. */
    meshId: string;
    materialId: string;
    seed: number;
    /** Instances per world unit squared at full painted density. */
    densityPerUnit: number;
    /** Painted density, one channel over the terrain grid. */
    density: SplatMap;
    /** Normalised height band, 0..1 of the terrain's height range. */
    minHeight: number;
    maxHeight: number;
    /** Slope band in degrees from horizontal. */
    minSlope: number;
    maxSlope: number;
    minScale: number;
    maxScale: number;
    /** Tilt instances onto the surface normal instead of standing upright. */
    alignToNormal: boolean;
    /** Random yaw range in degrees. */
    randomYaw: number;
}

export const DEFAULT_SCATTER: Omit<ScatterSet, 'id' | 'name' | 'density'> = {
    meshId: '',
    materialId: '',
    seed: 1337,
    densityPerUnit: 0.05,
    minHeight: 0,
    maxHeight: 1,
    minSlope: 0,
    maxSlope: 30,
    minScale: 0.8,
    maxScale: 1.2,
    alignToNormal: false,
    randomYaw: 360,
};

export interface ScatterInstance {
    position: [number, number, number];
    /** Euler XYZ in radians. */
    rotation: [number, number, number];
    scale: number;
}

export function createScatterSet(
    id: string,
    name: string,
    heightmap: Heightmap,
): ScatterSet {
    // Density starts empty: a new set should place nothing until the artist
    // paints where it belongs.
    return { ...DEFAULT_SCATTER, id, name, density: createDensityMap(heightmap) };
}

/**
 * Deterministic hash → [0,1). Indexed by cell and a channel number so one cell
 * can draw several independent values (jitter, scale, yaw) without a sequence.
 *
 * The engine reproduces this exactly in `Terrain\TerrainScatterGenerator::hash()`
 * so the runtime forest matches the preview. Exported so both sides can be
 * pinned to the same reference values in tests — do not change it without
 * updating the engine port and both test fixtures.
 */
export function scatterHash(cell: number, channel: number, seed: number): number {
    let h = cell * 374761393 + channel * 668265263 + seed * 1274126177;
    h = (h ^ (h >>> 13)) >>> 0;
    h = Math.imul(h, 1274126177) >>> 0;
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

/**
 * Generate instances for a scatter set.
 *
 * One candidate per grid cell, accepted probabilistically against the painted
 * density and the height/slope rules. Cell-based candidates rather than global
 * random sampling is what makes the result stable and lets the density brush
 * have a local effect.
 *
 * @param limit Hard cap on returned instances, so a maxed-out density brush on
 *              a large terrain cannot lock the editor up. Generation stops at
 *              the cap rather than thinning, so the shortfall is visible.
 */
export function generateScatterInstances(
    set: ScatterSet,
    heightmap: Heightmap,
    limit = 20000,
): ScatterInstance[] {
    const instances: ScatterInstance[] = [];
    if (set.densityPerUnit <= 0) return instances;

    const samples = sampleCountOf(set.density);
    if (samples === 0) return instances;

    // Expected instances per cell = density (per unit²) × the cell's area.
    const cellArea =
        (heightmap.sizeX / (heightmap.gridWidth - 1)) *
        (heightmap.sizeZ / (heightmap.gridDepth - 1));
    const perCell = set.densityPerUnit * cellArea;

    const range = heightmap.maxHeight - heightmap.minHeight;
    const stepX = heightmap.sizeX / (heightmap.gridWidth - 1);
    const stepZ = heightmap.sizeZ / (heightmap.gridDepth - 1);

    for (let z = 0; z < set.density.gridDepth && instances.length < limit; z++) {
        for (let x = 0; x < set.density.gridWidth && instances.length < limit; x++) {
            const cell = z * set.density.gridWidth + x;
            const painted = set.density.weights[cell] / 255;
            if (painted <= 0) continue;

            const chance = perCell * painted;
            if (scatterHash(cell, 0, set.seed) >= chance) continue;

            // Jitter inside the cell so instances do not sit on a visible grid.
            const jitterX = (scatterHash(cell, 1, set.seed) - 0.5) * stepX;
            const jitterZ = (scatterHash(cell, 2, set.seed) - 0.5) * stepZ;
            const wx = worldX(heightmap, x) + jitterX;
            const wz = worldZ(heightmap, z) + jitterZ;

            const normalisedHeight =
                range === 0 ? 0 : (heightAt(heightmap, x, z) - heightmap.minHeight) / range;
            if (normalisedHeight < set.minHeight || normalisedHeight > set.maxHeight) continue;

            const slope = slopeAt(heightmap, x, z);
            if (slope < set.minSlope || slope > set.maxSlope) continue;

            const scale =
                set.minScale + scatterHash(cell, 3, set.seed) * (set.maxScale - set.minScale);
            const yaw = (scatterHash(cell, 4, set.seed) * set.randomYaw * Math.PI) / 180;

            let rotation: [number, number, number] = [0, yaw, 0];
            if (set.alignToNormal) {
                const [nx, ny, nz] = normalAt(heightmap, x, z);
                // Tilt towards the surface: pitch/roll from the normal's
                // horizontal components, yaw kept as the random spin.
                rotation = [Math.atan2(nz, ny), yaw, -Math.atan2(nx, ny)];
            }

            instances.push({
                position: [wx, heightAt(heightmap, x, z), wz],
                rotation,
                scale,
            });
        }
    }

    return instances;
}

/** Instance count without building the transforms, for a UI readout. */
export function countScatterInstances(
    set: ScatterSet,
    heightmap: Heightmap,
    limit = 20000,
): number {
    return generateScatterInstances(set, heightmap, limit).length;
}
