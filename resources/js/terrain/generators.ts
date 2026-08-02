/**
 * Procedural height generators — the starting shapes an artist sculpts on top
 * of, rather than a replacement for sculpting.
 *
 * Noise is generated here in TypeScript and the *result* is what gets stored,
 * so the engine never needs a matching noise implementation. That is a
 * deliberate boundary: keeping the sampled heightmap authoritative means there
 * is no way for the editor's preview and the runtime terrain to drift apart
 * over a floating-point detail in two separate noise functions.
 *
 * The value-noise basis is seeded and deterministic, so re-entering the same
 * seed reproduces the same landscape.
 */

import { type Heightmap, createHeightmap, worldX, worldZ } from './heightmap';

export type GeneratorType = 'flat' | 'perlin' | 'ridged' | 'terraced' | 'island' | 'dunes';

export interface GeneratorDef {
    type: GeneratorType;
    label: string;
    hint: string;
}

export const GENERATORS: readonly GeneratorDef[] = [
    { type: 'flat', label: 'Flat', hint: 'Level ground at the base height.' },
    { type: 'perlin', label: 'Rolling hills', hint: 'Layered value noise — soft, natural terrain.' },
    { type: 'ridged', label: 'Mountains', hint: 'Ridged noise with sharp crests and valleys.' },
    { type: 'terraced', label: 'Terraces', hint: 'Rolling hills quantised into stepped plateaus.' },
    { type: 'island', label: 'Island', hint: 'Hills faded into water towards the edges.' },
    { type: 'dunes', label: 'Dunes', hint: 'Directional wave ridges with noise applied on top.' },
];

export interface GeneratorSettings {
    type: GeneratorType;
    seed: number;
    /** Feature size in world units — bigger means broader hills. */
    scale: number;
    /** Layers of detail; each octave doubles frequency and halves amplitude. */
    octaves: number;
    /** How much each successive octave contributes, 0..1. */
    persistence: number;
    /** Output height, 0..1 of the terrain's height range. */
    amplitude: number;
    /** Baseline the result is added to, 0..1 of the height range. */
    baseLevel: number;
    /** Step count for `terraced`. */
    terraces: number;
}

export const DEFAULT_GENERATOR: GeneratorSettings = {
    type: 'perlin',
    seed: 1337,
    scale: 80,
    octaves: 4,
    persistence: 0.5,
    amplitude: 0.6,
    baseLevel: 0.15,
    terraces: 6,
};

/**
 * Small, fast, seeded hash → [0,1).
 *
 * A hash rather than a PRNG sequence because value noise needs to look up a
 * *coordinate's* random value repeatedly and in any order; a sequential
 * generator cannot do that.
 */
function hash2(x: number, y: number, seed: number): number {
    let h = x * 374761393 + y * 668265263 + seed * 1274126177;
    h = (h ^ (h >>> 13)) >>> 0;
    h = Math.imul(h, 1274126177) >>> 0;
    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

function smoothstep(t: number): number {
    return t * t * (3 - 2 * t);
}

/** Bilinearly interpolated value noise at a point, in [0,1]. */
function valueNoise(x: number, y: number, seed: number): number {
    const xi = Math.floor(x);
    const yi = Math.floor(y);
    const xf = smoothstep(x - xi);
    const yf = smoothstep(y - yi);

    const v00 = hash2(xi, yi, seed);
    const v10 = hash2(xi + 1, yi, seed);
    const v01 = hash2(xi, yi + 1, seed);
    const v11 = hash2(xi + 1, yi + 1, seed);

    return (
        v00 * (1 - xf) * (1 - yf) +
        v10 * xf * (1 - yf) +
        v01 * (1 - xf) * yf +
        v11 * xf * yf
    );
}

/** Summed octaves of value noise, normalised to [0,1]. */
function fbm(x: number, y: number, settings: GeneratorSettings): number {
    let total = 0;
    let amplitude = 1;
    let frequency = 1;
    let normalisation = 0;

    const octaves = Math.max(1, Math.floor(settings.octaves));
    for (let i = 0; i < octaves; i++) {
        total += valueNoise(x * frequency, y * frequency, settings.seed + i * 7919) * amplitude;
        normalisation += amplitude;
        amplitude *= settings.persistence;
        frequency *= 2;
    }

    return normalisation === 0 ? 0 : total / normalisation;
}

/** Ridged noise: folds the basis around 0.5 so crests form sharp lines. */
function ridged(x: number, y: number, settings: GeneratorSettings): number {
    let total = 0;
    let amplitude = 1;
    let frequency = 1;
    let normalisation = 0;

    const octaves = Math.max(1, Math.floor(settings.octaves));
    for (let i = 0; i < octaves; i++) {
        const n = valueNoise(x * frequency, y * frequency, settings.seed + i * 7919);
        const fold = 1 - Math.abs(n * 2 - 1);
        total += fold * fold * amplitude;
        normalisation += amplitude;
        amplitude *= settings.persistence;
        frequency *= 2;
    }

    return normalisation === 0 ? 0 : total / normalisation;
}

function clamp01(value: number): number {
    return value < 0 ? 0 : value > 1 ? 1 : value;
}

/**
 * Generate a heightmap into the given grid/world options.
 *
 * Returns a fresh heightmap rather than mutating: generating is a discrete,
 * undoable action, unlike a brush stroke.
 */
export function generateHeightmap(map: Heightmap, settings: GeneratorSettings): Heightmap {
    const result = createHeightmap(map);
    const scale = Math.max(1e-3, settings.scale);

    for (let z = 0; z < result.gridDepth; z++) {
        const wz = worldZ(result, z);
        for (let x = 0; x < result.gridWidth; x++) {
            const wx = worldX(result, x);
            const nx = wx / scale;
            const nz = wz / scale;

            let value: number;
            switch (settings.type) {
                case 'flat':
                    value = 0;
                    break;
                case 'ridged':
                    value = ridged(nx, nz, settings);
                    break;
                case 'terraced': {
                    const steps = Math.max(1, Math.floor(settings.terraces));
                    value = Math.round(fbm(nx, nz, settings) * steps) / steps;
                    break;
                }
                case 'island':
                    value = fbm(nx, nz, settings) * islandFalloff(result, wx, wz);
                    break;
                case 'dunes': {
                    // A directional wave carries the dune shape; noise breaks up
                    // the regularity so it does not read as corrugated iron.
                    const wave = (Math.sin(nx * Math.PI * 2) + 1) / 2;
                    value = wave * 0.65 + fbm(nx, nz, settings) * 0.35;
                    break;
                }
                case 'perlin':
                default:
                    value = fbm(nx, nz, settings);
                    break;
            }

            result.samples[z * result.gridWidth + x] = clamp01(
                settings.baseLevel + value * settings.amplitude,
            );
        }
    }

    return result;
}

/**
 * Radial fade to zero towards the terrain edge, so an island ends in water
 * rather than at a cliff along the terrain border.
 */
function islandFalloff(map: Heightmap, wx: number, wz: number): number {
    const u = (wx / (map.sizeX * 0.5)) ** 2;
    const v = (wz / (map.sizeZ * 0.5)) ** 2;
    const distance = Math.sqrt(u + v);

    if (distance >= 1) return 0;
    return 1 - smoothstep(distance);
}

/**
 * Import a greyscale image as a heightmap.
 *
 * Luminance maps to the full height range. The image is sampled onto the
 * terrain grid rather than the grid being resized to the image, so an imported
 * heightmap drops into an existing terrain's world extents unchanged.
 */
export function heightmapFromImageData(map: Heightmap, image: ImageData): Heightmap {
    const result = createHeightmap(map);

    for (let z = 0; z < result.gridDepth; z++) {
        // Nearest-neighbour: heightmap images are usually authored at or above
        // the grid resolution, and interpolating would soften genuine detail.
        const sy = Math.min(
            image.height - 1,
            Math.round((z / (result.gridDepth - 1)) * (image.height - 1)),
        );
        for (let x = 0; x < result.gridWidth; x++) {
            const sx = Math.min(
                image.width - 1,
                Math.round((x / (result.gridWidth - 1)) * (image.width - 1)),
            );
            const offset = (sy * image.width + sx) * 4;
            const luminance =
                (image.data[offset] * 0.299 +
                    image.data[offset + 1] * 0.587 +
                    image.data[offset + 2] * 0.114) /
                255;

            result.samples[z * result.gridWidth + x] = clamp01(luminance);
        }
    }

    return result;
}

/**
 * Render the heightmap as 8-bit greyscale ImageData, for export.
 *
 * Note this is lossy relative to the stored 16-bit payload; it is an
 * interchange format for other tools, not a round-trip of the asset.
 */
export function heightmapToImageData(map: Heightmap): ImageData {
    const image = new ImageData(map.gridWidth, map.gridDepth);

    for (let i = 0; i < map.samples.length; i++) {
        const value = Math.round(clamp01(map.samples[i]) * 255);
        const offset = i * 4;
        image.data[offset] = value;
        image.data[offset + 1] = value;
        image.data[offset + 2] = value;
        image.data[offset + 3] = 255;
    }

    return image;
}
