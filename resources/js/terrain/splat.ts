/**
 * Splat maps — per-layer texture coverage painted over a terrain.
 *
 * Layout is **layer-major**: `weights[layer * sampleCount + z * gridWidth + x]`.
 * Painting touches one layer at a time, and a layer-major buffer keeps each
 * layer's samples contiguous, so a brush walks memory linearly instead of
 * striding across every layer's byte for each cell.
 *
 * Weights are bytes. Coverage is inherently 8-bit — a blend factor with 256
 * levels is already past what survives texture filtering — and one byte per
 * sample per layer keeps a 4-layer 257x257 terrain at 264 KB rather than the
 * megabyte a float array would cost.
 *
 * Layer 0 is the base: {@link normaliseSplat} gives it whatever coverage the
 * other layers leave over, so there is never an unpainted hole in the terrain.
 */

import type { BrushSettings } from './brushes';
import {
    type Heightmap,
    type HeightmapOptions,
    bytesToBase64,
    base64ToBytes,
    gridCoordsAt,
    heightAt,
    slopeAt,
    worldX,
    worldZ,
} from './heightmap';

export interface SplatMap {
    gridWidth: number;
    gridDepth: number;
    layerCount: number;
    weights: Uint8Array;
}

/** Height/slope thresholds a layer can be auto-filled from. */
export interface LayerRules {
    /** Normalised height band, 0..1 of the terrain's height range. */
    minHeight: number;
    maxHeight: number;
    /** Slope band in degrees from horizontal. */
    minSlope: number;
    maxSlope: number;
}

export function createSplatMap(options: HeightmapOptions, layerCount: number): SplatMap {
    const gridWidth = Math.max(2, options.gridWidth);
    const gridDepth = Math.max(2, options.gridDepth);
    const count = Math.max(0, layerCount);

    const map: SplatMap = {
        gridWidth,
        gridDepth,
        layerCount: count,
        weights: new Uint8Array(gridWidth * gridDepth * count),
    };

    // A fresh map is fully covered by the base layer, matching what
    // normaliseSplat would produce for an otherwise empty terrain.
    if (count > 0) map.weights.fill(255, 0, gridWidth * gridDepth);

    return map;
}

/**
 * A single-channel coverage map that starts **empty**.
 *
 * Structurally the same buffer as a splat map, but semantically the opposite at
 * both ends: scatter density starts at zero (you paint where objects go, rather
 * than carving them out of a full field) and must never be normalised, because
 * "no coverage" is a legitimate final state rather than an unpainted hole.
 * Passing one through {@link createSplatMap} or {@link normaliseSplat} would
 * fill it to 255 and drop a forest over the whole terrain.
 */
export function createDensityMap(options: HeightmapOptions): SplatMap {
    const gridWidth = Math.max(2, options.gridWidth);
    const gridDepth = Math.max(2, options.gridDepth);

    return {
        gridWidth,
        gridDepth,
        layerCount: 1,
        weights: new Uint8Array(gridWidth * gridDepth),
    };
}

/**
 * Resample a density map onto a new grid, preserving "empty means empty".
 *
 * Deliberately does not normalise — see {@link createDensityMap}.
 */
export function resizeDensityMap(map: SplatMap, options: HeightmapOptions): SplatMap {
    const next = createDensityMap(options);

    for (let z = 0; z < next.gridDepth; z++) {
        const sz = Math.min(
            map.gridDepth - 1,
            Math.round((z / (next.gridDepth - 1)) * (map.gridDepth - 1)),
        );
        for (let x = 0; x < next.gridWidth; x++) {
            const sx = Math.min(
                map.gridWidth - 1,
                Math.round((x / (next.gridWidth - 1)) * (map.gridWidth - 1)),
            );
            next.weights[z * next.gridWidth + x] = map.weights[sz * map.gridWidth + sx];
        }
    }

    return next;
}

/** Decode a density payload, falling back to empty rather than to full coverage. */
export function decodeDensityMap(encoded: string, options: HeightmapOptions): SplatMap {
    const fresh = createDensityMap(options);
    if (!encoded) return fresh;

    let bytes: Uint8Array;
    try {
        bytes = base64ToBytes(encoded);
    } catch {
        return fresh;
    }
    if (bytes.length !== fresh.weights.length) return fresh;

    fresh.weights.set(bytes);
    return fresh;
}

export function sampleCountOf(map: SplatMap): number {
    return map.gridWidth * map.gridDepth;
}

export function weightAt(map: SplatMap, layer: number, x: number, z: number): number {
    if (layer < 0 || layer >= map.layerCount) return 0;
    const cx = x < 0 ? 0 : x > map.gridWidth - 1 ? map.gridWidth - 1 : x;
    const cz = z < 0 ? 0 : z > map.gridDepth - 1 ? map.gridDepth - 1 : z;
    return map.weights[layer * sampleCountOf(map) + cz * map.gridWidth + cx];
}

export function encodeSplat(map: SplatMap): string {
    return map.layerCount === 0 ? '' : bytesToBase64(map.weights);
}

/**
 * Decode a splat payload. A payload that does not fit the grid and layer count
 * yields a fresh map rather than an error — the same tolerance the heightmap
 * decoder applies, so a resolution edit cannot corrupt a load.
 */
export function decodeSplat(
    encoded: string,
    options: HeightmapOptions,
    layerCount: number,
): SplatMap {
    const fresh = createSplatMap(options, layerCount);
    if (!encoded || layerCount === 0) return fresh;

    let bytes: Uint8Array;
    try {
        bytes = base64ToBytes(encoded);
    } catch {
        return fresh;
    }
    if (bytes.length !== fresh.weights.length) return fresh;

    fresh.weights.set(bytes);
    return fresh;
}

/**
 * Resample a splat map onto a new grid and/or layer count.
 *
 * `removedLayer` drops that layer's channel while preserving the rest, so
 * deleting a layer does not shuffle the remaining ones' coverage.
 */
export function resizeSplat(
    map: SplatMap,
    options: HeightmapOptions,
    layerCount: number,
    removedLayer?: number,
): SplatMap {
    const next = createSplatMap(options, layerCount);
    if (layerCount === 0) return next;

    const nextSamples = sampleCountOf(next);
    const previousSamples = sampleCountOf(map);

    for (let layer = 0; layer < layerCount; layer++) {
        // Map each destination layer back to its source channel, skipping the
        // removed one.
        const source =
            removedLayer !== undefined && layer >= removedLayer ? layer + 1 : layer;
        if (source >= map.layerCount) {
            next.weights.fill(0, layer * nextSamples, (layer + 1) * nextSamples);
            continue;
        }

        for (let z = 0; z < next.gridDepth; z++) {
            // Nearest-neighbour: coverage is a painted mask, and interpolating
            // it would bleed layers into each other at every resize.
            const sz = Math.min(
                map.gridDepth - 1,
                Math.round((z / (next.gridDepth - 1)) * (map.gridDepth - 1)),
            );
            for (let x = 0; x < next.gridWidth; x++) {
                const sx = Math.min(
                    map.gridWidth - 1,
                    Math.round((x / (next.gridWidth - 1)) * (map.gridWidth - 1)),
                );
                next.weights[layer * nextSamples + z * next.gridWidth + x] =
                    map.weights[source * previousSamples + sz * map.gridWidth + sx];
            }
        }
    }

    normaliseSplat(next);
    return next;
}

function clampByte(value: number): number {
    return value < 0 ? 0 : value > 255 ? 255 : Math.round(value);
}

/** Falloff weight, matching the sculpt brushes so both tools feel the same. */
function brushWeight(distance: number, radius: number, falloff: number): number {
    if (distance >= radius) return 0;
    const normalized = distance / radius;
    const inner = 1 - Math.min(1, Math.max(0, falloff));
    if (normalized <= inner) return 1;
    const t = (normalized - inner) / (1 - inner || 1);
    return 1 - t * t * (3 - 2 * t);
}

export interface PaintApplication {
    layer: number;
    settings: BrushSettings;
    worldX: number;
    worldZ: number;
    dt: number;
    /** Alt/right-drag: remove this layer's coverage instead of adding it. */
    erase: boolean;
}

/**
 * Paint one layer's coverage under the brush.
 *
 * Every painted cell is renormalised immediately so the layer weights at that
 * cell keep summing to 255. Doing it per cell rather than in a pass at the end
 * is what makes painting read correctly while the stroke is still in progress.
 */
export function paintLayer(
    map: SplatMap,
    heightmap: Heightmap,
    { layer, settings, worldX: wx, worldZ: wz, dt, erase }: PaintApplication,
): boolean {
    if (map.layerCount === 0 || layer < 0 || layer >= map.layerCount) return false;
    if (settings.radius <= 0 || settings.strength <= 0) return false;

    const [gx, gz] = gridCoordsAt(heightmap, wx, wz);
    const cellsX = (settings.radius / heightmap.sizeX) * (heightmap.gridWidth - 1);
    const cellsZ = (settings.radius / heightmap.sizeZ) * (heightmap.gridDepth - 1);

    const x0 = Math.max(0, Math.floor(gx - cellsX));
    const x1 = Math.min(map.gridWidth - 1, Math.ceil(gx + cellsX));
    const z0 = Math.max(0, Math.floor(gz - cellsZ));
    const z1 = Math.min(map.gridDepth - 1, Math.ceil(gz + cellsZ));

    const amount = Math.min(1, settings.strength * dt * 4) * 255;
    const samples = sampleCountOf(map);
    let touched = false;

    for (let z = z0; z <= z1; z++) {
        const cellZ = worldZ(heightmap, z);
        for (let x = x0; x <= x1; x++) {
            const dx = worldX(heightmap, x) - wx;
            const dz = cellZ - wz;
            const weight = brushWeight(
                Math.sqrt(dx * dx + dz * dz),
                settings.radius,
                settings.falloff,
            );
            if (weight <= 0) continue;

            const cell = z * map.gridWidth + x;
            const index = layer * samples + cell;
            const delta = amount * weight * (erase ? -1 : 1);
            map.weights[index] = clampByte(map.weights[index] + delta);
            normaliseCell(map, cell, layer);
            touched = true;
        }
    }

    return touched;
}

/**
 * Rescale the other layers at one cell so all weights sum to 255, holding the
 * just-painted layer at its new value.
 */
function normaliseCell(map: SplatMap, cell: number, keptLayer: number): void {
    const samples = sampleCountOf(map);
    const kept = map.weights[keptLayer * samples + cell];
    const remaining = 255 - kept;

    let othersTotal = 0;
    for (let layer = 0; layer < map.layerCount; layer++) {
        if (layer !== keptLayer) othersTotal += map.weights[layer * samples + cell];
    }

    if (othersTotal === 0) {
        // Nothing to scale: give the leftover to the base layer, unless the base
        // is the layer being painted — then the cell is fully covered already.
        if (keptLayer !== 0) map.weights[cell] = remaining;
        return;
    }

    let assigned = 0;
    let lastOther = -1;
    for (let layer = 0; layer < map.layerCount; layer++) {
        if (layer === keptLayer) continue;
        const scaled = clampByte((map.weights[layer * samples + cell] / othersTotal) * remaining);
        map.weights[layer * samples + cell] = scaled;
        assigned += scaled;
        lastOther = layer;
    }

    // Rounding can leave the total a byte or two off; push the difference into
    // the last non-painted layer so the cell always sums to exactly 255.
    if (lastOther >= 0 && assigned !== remaining) {
        const index = lastOther * samples + cell;
        map.weights[index] = clampByte(map.weights[index] + (remaining - assigned));
    }
}

/** Renormalise every cell so its layer weights sum to 255. */
export function normaliseSplat(map: SplatMap): void {
    if (map.layerCount === 0) return;

    const samples = sampleCountOf(map);
    for (let cell = 0; cell < samples; cell++) {
        let total = 0;
        for (let layer = 0; layer < map.layerCount; layer++) {
            total += map.weights[layer * samples + cell];
        }

        if (total === 0) {
            // An empty cell falls back to the base layer rather than rendering
            // as an untextured hole.
            map.weights[cell] = 255;
            continue;
        }
        if (total === 255) continue;

        let assigned = 0;
        for (let layer = 0; layer < map.layerCount; layer++) {
            const index = layer * samples + cell;
            const scaled = clampByte((map.weights[index] / total) * 255);
            map.weights[index] = scaled;
            assigned += scaled;
        }
        if (assigned !== 255) {
            map.weights[cell] = clampByte(map.weights[cell] + (255 - assigned));
        }
    }
}

/**
 * Fill a layer's coverage from its height and slope rules.
 *
 * This is an authoring aid, not runtime state: it gives an artist a sensible
 * starting distribution ("rock on anything steeper than 35°") that they then
 * correct by hand. Because the rules live in the asset, the fill can be re-run
 * after sculpting without re-entering the thresholds.
 */
export function fillLayerByRules(
    map: SplatMap,
    heightmap: Heightmap,
    layer: number,
    rules: LayerRules,
): void {
    if (layer < 0 || layer >= map.layerCount) return;

    const samples = sampleCountOf(map);
    const range = heightmap.maxHeight - heightmap.minHeight;

    for (let z = 0; z < map.gridDepth; z++) {
        for (let x = 0; x < map.gridWidth; x++) {
            const normalisedHeight =
                range === 0 ? 0 : (heightAt(heightmap, x, z) - heightmap.minHeight) / range;
            const slope = slopeAt(heightmap, x, z);

            const inBand =
                normalisedHeight >= rules.minHeight &&
                normalisedHeight <= rules.maxHeight &&
                slope >= rules.minSlope &&
                slope <= rules.maxSlope;

            map.weights[layer * samples + z * map.gridWidth + x] = inBand ? 255 : 0;
        }
    }
}

/**
 * Per-vertex layer weights for previewing the blend, as a Float32Array of
 * `layerCount` values per vertex in grid order.
 */
export function splatToVertexWeights(map: SplatMap): Float32Array {
    const samples = sampleCountOf(map);
    const out = new Float32Array(samples * Math.max(1, map.layerCount));
    if (map.layerCount === 0) return out;

    for (let cell = 0; cell < samples; cell++) {
        for (let layer = 0; layer < map.layerCount; layer++) {
            out[cell * map.layerCount + layer] = map.weights[layer * samples + cell] / 255;
        }
    }

    return out;
}
